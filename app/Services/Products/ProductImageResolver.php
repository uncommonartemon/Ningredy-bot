<?php

namespace App\Services\Products;

use App\Exceptions\ProductSourceHostDriftException;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Photos\ImageUrls;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductImageResolver
{
    public function __construct(
        private readonly BrowserProductGalleryExtractor $browser,
        private readonly AiSettings $settings,
        private readonly ProductSourceMetrics $metrics,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly ProductSourcePageRules $pageRules,
        private readonly BrowserProductImageTransferStore $transfers,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly HostReputation $reputation,
        private readonly GalleryDownloadCache $galleryDownloads,
        private readonly SafeProductHttp $http,
    ) {}

    /** @var array<int, true> */
    private array $reportedBudgetExhaustion = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceContextsByImageUrl = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceContextsByPageUrl = [];

    /**
     * Keyed by page URL, unlike sourceContextsByImageUrl (keyed by image
     * asset URL) - resolve() may run for a page whose gallery yields no new
     * images at all (nothing to key an image-based lookup on), and the
     * later specification-reconciliation step needs to look a page up by
     * its own URL, which is all it has (ProductDraft.primary_source_url).
     *
     * @var array<string, string>
     */
    private array $specificationTextByPageUrl = [];

    /** @return array<string, mixed>|null */
    public function sourceContextForImage(string $imageUrl): ?array
    {
        return $this->sourceContextsByImageUrl[ImageUrls::normalizeCandidateUrl($imageUrl)] ?? null;
    }

    /** Page evidence survives an empty gallery, including a rejected scout. */
    public function sourceContextForPage(string $pageUrl): ?array
    {
        return $this->sourceContextsByPageUrl[ImageUrls::normalizeCandidateUrl($pageUrl)] ?? null;
    }

    public function specificationTextForPage(string $pageUrl): ?string
    {
        return $this->specificationTextByPageUrl[ImageUrls::normalizeCandidateUrl($pageUrl)] ?? null;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    public function preflightSource(array $source, ?int $telegramUpdateId = null): array
    {
        $url = is_string($source['url'] ?? null) ? $source['url'] : '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $result = [
            'blocked' => false,
            'unavailable' => false,
            'reason' => null,
            'static_image_urls' => [],
            'active_recipe' => false,
            'known_recipe_domain' => false,
            'browser_probe_required' => false,
            'final_url' => $url,
            'identity_evidence' => '',
            'specification_text' => '',
            'page_identity' => [],
        ];

        if ($url !== '' && ($pageRule = $this->pageRules->activeRuleFor($url))) {
            $this->pageRules->recordHit($pageRule);
            $result['blocked'] = true;
            $result['reason'] = 'known_unsuitable_page';
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_url' => $url,
                'actor' => 'http_preflight',
                'phase' => 'gallery_preflight',
                'action' => 'apply_page_rule',
                'status' => 'failed',
                'decision' => 'known_unsuitable_page',
                'output' => [
                    'page_kind' => $pageRule->page_kind,
                    'reason' => $pageRule->reason,
                    'confidence' => $pageRule->confidence,
                ],
            ]);

            return $result;
        }

        if ($url === '' || ! $this->isPublicUrl($url) || $this->looksLikeNonHtmlDocumentUrl($url)) {
            return [...$result, 'unavailable' => true, 'reason' => 'invalid_or_non_html_url'];
        }

        try {
            $recipe = $this->recipeRouter->recipeForUrl($url);
            $result['active_recipe'] = $recipe?->status === 'active';
            $result['known_recipe_domain'] = $this->recipeRouter->domainHasActiveRecipe($url);

            if ($this->recipeRouter->domainIsBlocked($url)) {
                $result['blocked'] = true;
                $result['reason'] = 'known_domain_block';
            } else {
                [$response, $finalUrl] = $this->fetch($url);

                if (! $this->isHtmlResponse($response)) {
                    $result['unavailable'] = true;
                    $result['reason'] = 'non_html';
                } elseif ($this->looksLikeAccessGate($response->body())) {
                    // An HTTP client can see a JS/cookie gate while the real
                    // Chromium session still opens the product. Disable only
                    // the static preflight result; let Playwright make the
                    // authoritative accessibility decision.
                    $result['browser_probe_required'] = true;
                    $result['reason'] = 'access_gate';
                } else {
                    $html = $response->body();
                    $result['final_url'] = $finalUrl;
                    $result['identity_evidence'] = $this->extractPageIdentityEvidence($html, $finalUrl);
                    $result['specification_text'] = $this->extractPageSpecificationText($html, $finalUrl);
                    $result['static_image_urls'] = $this->extractPageImages($html, $finalUrl);
                    $result['page_identity'] = ProductSourcePageMetadata::fromHtml($html, $finalUrl);
                }
            }
        } catch (Throwable $exception) {
            $status = $exception instanceof RequestException ? $exception->response->status() : null;
            $result['unavailable'] = in_array($status, [404, 410], true);
            // A connection timeout/DNS failure has no status at all (it
            // isn't a RequestException - the server never answered this
            // cheap client within its short fast-fail timeout), but that is
            // at least as promising a candidate for a real browser retry as
            // an explicit 403: slow-walking or black-holing a non-browser
            // request instead of returning a clean block status is itself a
            // common anti-bot tactic, and Playwright's own timeout/budget is
            // not bound by this quick check's. Only a confirmed 404/410
            // (the page itself does not exist) is not worth retrying.
            $result['browser_probe_required'] = ! $result['unavailable'];
            $result['reason'] = $status ? 'http_'.$status : 'http_error';
        }

        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_url' => $url,
            'actor' => 'http_preflight',
            'phase' => 'gallery_preflight',
            'action' => 'check_source_access',
            'status' => ($result['blocked'] || $result['unavailable']) ? 'failed' : 'completed',
            'decision' => $result['reason'] ?: 'candidate_ranked',
            'output' => [
                'active_recipe' => $result['active_recipe'],
                'known_recipe_domain' => $result['known_recipe_domain'],
                'static_count' => count($result['static_image_urls']),
                'identity_evidence_present' => $result['identity_evidence'] !== '',
                'page_rule' => $result['reason'] === 'known_unsuitable_page',
            ],
        ]);

        return $result;
    }

    /**
     * Fetches one specific page a specification-reconciliation agent asked
     * for by URL (see App\Ai\Tools\FetchProductSourcePageText - the caller
     * is responsible for confirming the REQUESTED URL is on an allowed host
     * and checking the shared search budget before ever reaching this
     * method) and returns whatever specs-oriented text it can find there,
     * reusing the exact same safe-fetch machinery preflightSource() already
     * uses. Never throws - a page that cannot be read returns an empty
     * 'text', which the agent already treats as "no evidence either way",
     * never as evidence of anything.
     *
     * Checking the REQUESTED url's host is not enough: a redirect (HTTP or,
     * for the browser path, a navigation the click itself triggered) can
     * land on a different host than the one that was actually validated. A
     * request that drifted off the requested host returns 'blocked_reason'
     * and an empty 'text' instead of silently handing over a different
     * site's content as if it were this one's - 'final_url' says exactly
     * where it actually ended up either way, so the caller can report the
     * truth rather than staying silent about what happened.
     *
     * $telegramUpdateId is threaded into the browser call so its deadline
     * comes from the same shared search-time budget as everything else in
     * this request, not the tool's own unrelated default timeout.
     *
     * $openSelector, when given, means the content the agent needs is behind
     * an in-page interaction (a "характеристики"/specifications tab) rather
     * than reachable by plain HTTP at all - this always goes straight to a
     * real browser session (BrowserProductGalleryExtractor::scoutAfterOpening())
     * rather than trying HTTP first, since HTTP cannot click anything. Its
     * own click either navigated to a different, unconfirmed product (caught
     * by the same evidence-based check the trained gallery recipe's own
     * clicks already answer to, not a URL/name guess) or stayed in place -
     * 'click_outcome' reports which, so a click that silently failed or
     * silently left the product is never reported as if it worked.
     *
     * $forceBrowser, when true and $openSelector is null, skips the plain-
     * HTTP attempt even if it would return some non-empty text - the agent
     * sets this when it already knows or suspects that text is too thin to
     * be the real content (e.g. only a title, the rest rendered by
     * JavaScript), rather than this method silently trusting "non-empty" as
     * "sufficient", a distinction only the agent asking is positioned to
     * judge.
     *
     * Absent either signal, a real browser session is still tried whenever
     * plain HTTP found nothing usable at all - an empty body, an access
     * gate, or non-HTML there is not proof the page has nothing on it, it is
     * the normal signature of a Playwright-only source (PROJECT_STRATEGY.md's
     * Playwright-first list). Either browser path also returns
     * 'observed_controls' - real, structurally-stable selectors the page
     * actually has, so a caller can hand an agent something to pick from
     * for its next open_selector rather than something to guess.
     *
     * 'identity_evidence' is what THIS page itself says it is - the same
     * generic, structural fingerprint (title/h1/meta/JSON-LD identifiers, or
     * for the browser path the canonical/og:url/JSON-LD Product fields
     * Playwright already reads) already used elsewhere to tell one product
     * from another - never a name-similarity guess. Being on the right host
     * does not mean this is the right product; this is handed to the caller
     * so the same judgment source_identity_snippet already grounds can be
     * applied here too, rather than trusting 'text' as confirmed just
     * because it loaded.
     *
     * @return array{
     *     text: string,
     *     final_url: string,
     *     via: 'none'|'http'|'browser',
     *     identity_evidence: string,
     *     observed_controls: array<int, array{selector: string, text: string}>,
     *     click_outcome: ?array{selector: string, clicked: bool, changed: bool, selector_missing: bool, navigated_away: bool, navigated_away_reason: ?string},
     *     blocked_reason: ?string,
     * }
     */
    public function fetchAdditionalPageText(
        string $url,
        ?int $telegramUpdateId = null,
        ?string $openSelector = null,
        bool $forceBrowser = false,
    ): array {
        $empty = [
            'text' => '', 'final_url' => $url, 'via' => 'none', 'identity_evidence' => '',
            'observed_controls' => [], 'click_outcome' => null, 'blocked_reason' => null,
        ];

        if ($url === '' || ! $this->isPublicUrl($url) || $this->looksLikeNonHtmlDocumentUrl($url)) {
            return $empty;
        }

        $requestedHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($openSelector === null && ! $forceBrowser) {
            try {
                [$response, $finalUrl] = $this->fetch($url, confineToHost: $requestedHost);

                if ($this->isHtmlResponse($response) && ! $this->looksLikeAccessGate($response->body())) {
                    $text = $this->extractPageSpecificationText($response->body(), $finalUrl);

                    if ($text !== '') {
                        return [
                            ...$empty,
                            'text' => $text,
                            'final_url' => $finalUrl,
                            'via' => 'http',
                            'identity_evidence' => $this->extractPageIdentityEvidence($response->body(), $finalUrl),
                        ];
                    }
                }
            } catch (ProductSourceHostDriftException $exception) {
                // A hop already landed off the confined host - refused
                // outright rather than tried again through the browser,
                // which would only repeat the same redirect.
                return [...$empty, 'final_url' => $exception->driftedUrl, 'blocked_reason' => 'redirected_off_host'];
            } catch (Throwable) {
                // Plain HTTP failed outright - still worth a real browser below.
            }
        }

        try {
            if ($openSelector !== null) {
                $this->browser->scoutAfterOpening(
                    $url,
                    $openSelector,
                    telegramUpdateId: $telegramUpdateId,
                    context: ['card_assembly' => true],
                    confineNavigationToHost: $requestedHost,
                );
            } else {
                // Only card assembly reads additional pages; its browser gets
                // the assembly window, not the search's.
                $this->browser->scout($url, telegramUpdateId: $telegramUpdateId, context: ['card_assembly' => true],
                    confineNavigationToHost: $requestedHost);
            }
        } catch (Throwable) {
            return $empty;
        }

        $finalUrl = $this->browser->lastProductPageUrl() ?? $url;

        if (strtolower((string) parse_url($finalUrl, PHP_URL_HOST)) !== $requestedHost) {
            return [...$empty, 'final_url' => $finalUrl, 'blocked_reason' => 'redirected_off_host'];
        }

        $identity = $this->browser->lastProductIdentity();

        return [
            'text' => (string) $this->browser->lastSpecificationText(),
            'final_url' => $finalUrl,
            'via' => 'browser',
            'identity_evidence' => $identity !== null
                ? mb_substr((string) json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 2000)
                : '',
            'observed_controls' => $this->browser->lastObservedControls(),
            'click_outcome' => $this->browser->lastClickOutcome(),
            'blocked_reason' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function resolve(
        array $sources,
        int $limit = 5,
        ?callable $debug = null,
        ?int $telegramUpdateId = null,
        bool $forceInteractive = false,
        bool $staticOnly = false,
        bool $activeRecipeOnly = false,
    ): array {
        $images = [];
        // Clear the requested pages before any budget/access early return.
        // An earlier successful visit must not identify this new attempt.
        foreach ($sources as $source) {
            $url = is_array($source) ? ($source['url'] ?? null) : null;
            if (! is_string($url)) {
                continue;
            }
            $key = ImageUrls::normalizeCandidateUrl($url);
            unset($this->sourceContextsByPageUrl[$key]);
            unset($this->specificationTextByPageUrl[$key]);
            foreach ($this->sourceContextsByPageUrl as $pageKey => $context) {
                if (($context['original_url'] ?? null) === $url) {
                    unset($this->sourceContextsByPageUrl[$pageKey]);
                    unset($this->specificationTextByPageUrl[$pageKey]);
                }
            }
            foreach ($this->sourceContextsByImageUrl as $imageKey => $context) {
                if (($context['original_url'] ?? null) === $url) {
                    unset($this->sourceContextsByImageUrl[$imageKey]);
                }
            }
        }
        // A research pass routinely returns four links to one manufacturer's
        // own shop. Opening all four is four visits to one host inside three
        // minutes, and if the first is refused the other three are refused too
        // - they were never independent chances, they were one shop asked four
        // times. Breadth across domains is what actually finds a gallery.
        $perHost = [];
        $perHostCap = max(1, (int) config('product-images.max_sources_per_host', 2));

        foreach (array_slice($sources, 0, (int) config('product-images.max_sources_per_resolve', 10)) as $source) {
            if (! $this->timeBudget->canStart($telegramUpdateId, 20)) {
                $debug?->__invoke('warning', 'Резерв времени достигнут; новые страницы не открываю, чтобы успеть сохранить результат и ответить в Telegram.');
                break;
            }

            if ($this->costBudget->exceeded($telegramUpdateId)) {
                if (! isset($this->reportedBudgetExhaustion[$telegramUpdateId])) {
                    $debug?->__invoke('warning', sprintf(
                        'Бюджет поиска исчерпан (~$%.2f); текущий источник завершён, новые источники не открываю.',
                        $this->costBudget->limit(),
                    ));
                    $this->reportedBudgetExhaustion[$telegramUpdateId] = true;
                }

                break;
            }

            $sourceUrl = is_array($source) ? ($source['url'] ?? null) : null;

            if (! is_string($sourceUrl) || ! $this->isPublicUrl($sourceUrl)) {
                continue;
            }

            if ($this->looksLikeNonHtmlDocumentUrl($sourceUrl)) {
                $debug?->__invoke('warning', 'Источник пропущен: ссылка ведёт на документ, а не на HTML-карточку товара.');
                $this->metrics->recordExtraction($sourceUrl, 0, 'non_html');

                continue;
            }

            $sourceHost = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

            // Asking a shop that has just refused twice is not another attempt,
            // it is the same refusal counted again on their side.
            if ($this->reputation->isRefusing($sourceUrl)) {
                $refusal = $this->reputation->refusal($sourceUrl) ?? [];
                $debug?->__invoke('warning', sprintf(
                    'Источник пропущен: %s отказал %d раз подряд (%s), даю домену остыть.',
                    $sourceHost,
                    (int) ($refusal['count'] ?? 0),
                    (string) ($refusal['reason'] ?? 'отказ'),
                ));
                $this->metrics->recordExtraction($sourceUrl, 0, 'host_refusing');

                continue;
            }

            $perHost[$sourceHost] = ($perHost[$sourceHost] ?? 0) + 1;

            if ($perHost[$sourceHost] > $perHostCap) {
                $debug?->__invoke('warning', sprintf(
                    'Источник пропущен: с %s в этом поиске уже открыто %d страниц, беру другие домены.',
                    $sourceHost,
                    $perHostCap,
                ));

                continue;
            }

            if ($pageRule = $this->pageRules->activeRuleFor($sourceUrl)) {
                $this->pageRules->recordHit($pageRule);
                $debug?->__invoke(
                    'warning',
                    'Страница ранее подтверждена как непригодная товарная карточка; пропускаю URL и перехожу к следующему источнику: '.$sourceUrl,
                );
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_url' => $sourceUrl,
                    'actor' => 'orchestrator',
                    'phase' => 'gallery_preflight',
                    'action' => 'apply_page_rule',
                    'status' => 'failed',
                    'decision' => 'known_unsuitable_page',
                    'output' => [
                        'page_kind' => $pageRule->page_kind,
                        'reason' => $pageRule->reason,
                        'confidence' => $pageRule->confidence,
                    ],
                ]);

                continue;
            }

            $sourceImageStart = count($images);
            $browserUrl = $sourceUrl;
            $browserEligible = true;
            $accessGateDetected = false;
            $browserImages = [];
            $failureKind = null;
            $identityEvidence = '';
            $sourceTitle = is_string($source['title'] ?? null) ? $source['title'] : null;
            $sourceIdentityAssessment = is_array($source['_observed_identity'] ?? null) ? $source['_observed_identity'] : null;
            $specificationText = '';
            $reportedSourceImages = collect(is_array($source['image_urls'] ?? null) ? $source['image_urls'] : [])
                ->filter(fn (mixed $imageUrl): bool => is_string($imageUrl) && $this->isPublicUrl($imageUrl))
                ->map(fn (string $imageUrl): string => ImageUrls::normalizeCandidateUrl($imageUrl))
                ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))->values()->all();

            try {
                [$response, $finalUrl] = $this->fetch($sourceUrl);
                $browserUrl = $finalUrl;

                if ($this->isHtmlResponse($response)) {
                    $html = $response->body();

                    if ($this->looksLikeAccessGate($html)) {
                        $accessGateDetected = true;
                        $failureKind = 'access_gate';
                        $this->reputation->noteRefusal($sourceUrl, HostReputation::REFUSAL_ACCESS_GATE);
                    } else {
                        $this->reputation->noteAcceptance($sourceUrl);
                        // The actual document's title is already included in
                        // extractPageIdentityEvidence; an older search title
                        // must not supply a missing SKU after a redirect.
                        $sourceTitle = null;
                        $identityEvidence = $this->extractPageIdentityEvidence($html, $finalUrl);
                        $specificationText = $this->extractPageSpecificationText($html, $finalUrl);
                        foreach ($this->extractPageImages($html, $finalUrl) as $imageUrl) {
                            if ($this->isPublicUrl($imageUrl)) {
                                $images[] = $imageUrl;
                            }
                        }
                    }
                } else {
                    $browserEligible = false;
                    $failureKind = 'non_html';
                    $contentType = strtolower(trim((string) $response->header('Content-Type')));
                    $debug?->__invoke(
                        'warning',
                        'Источник пропущен: ожидалась HTML-карточка товара, получен '.($contentType !== '' ? $contentType : 'неизвестный тип содержимого').'.',
                    );
                }
            } catch (Throwable $exception) {
                $failureKind = 'http_error';
                // Seven seconds and zero bytes is not a slow page, it is a host
                // that took the connection and said nothing. Counting it means
                // the browser does not go on to spend another thirty seconds
                // discovering the same thing.
                $this->reputation->noteRefusal($sourceUrl, HostReputation::REFUSAL_SILENCE);
                $debug?->__invoke('warning', 'HTML страницы недоступен: '.$exception->getMessage());
                Log::notice('Product image metadata source was unavailable.', [
                    'host' => parse_url($sourceUrl, PHP_URL_HOST),
                    'error' => $exception->getMessage(),
                ]);
            }

            $staticSourceImages = array_slice($images, $sourceImageStart);
            $browserSeedImages = array_values(array_unique([
                ...$reportedSourceImages,
                ...$staticSourceImages,
            ]));
            $browserMode = $this->settings->galleryBrowserMode();
            $shouldUseBrowser = $browserEligible
                && ! $staticOnly
                && $browserMode !== AiSettings::GALLERY_BROWSER_OFF;

            if ($shouldUseBrowser) {
                $browserContext = [
                    'static_image_urls' => $browserSeedImages,
                    'minimum_verified_images' => max(1, (int) ($source['_minimum_verified_images'] ?? 3)),
                    'category_slug' => $source['_category_slug'] ?? null,
                    // Only a source bound to a draft carries one; without it
                    // the trainer reports the request as unavailable.
                    ...(isset($source[ProductRequestContext::SOURCE_KEY])
                        ? [ProductRequestContext::CONTEXT_KEY => $source[ProductRequestContext::SOURCE_KEY]]
                        : []),
                    'minimum_image_width' => $source['_minimum_image_width'] ?? null,
                    'minimum_image_height' => $source['_minimum_image_height'] ?? null,
                    ...array_intersect_key($source, array_flip(['_photo_applicability', '_observed_identity'])),
                ];
                $browserResult = $activeRecipeOnly
                    ? $this->browser->extract(
                        $browserUrl,
                        $limit,
                        $debug,
                        $telegramUpdateId,
                        $browserContext,
                        $forceInteractive,
                        true,
                    )
                    : ($debug
                        ? $this->browser->extract(
                            $browserUrl,
                            $limit,
                            $debug,
                            $telegramUpdateId,
                            $browserContext,
                            $forceInteractive,
                        )
                        : $this->browser->extract(
                            $browserUrl,
                            $limit,
                            telegramUpdateId: $telegramUpdateId,
                            context: $browserContext,
                            forceInteractive: $forceInteractive,
                        ));
                $browserImages = collect($browserResult)
                    ->filter(fn (mixed $imageUrl): bool => is_string($imageUrl) && $this->isPublicUrl($imageUrl))
                    ->map(fn (string $imageUrl): string => ImageUrls::normalizeCandidateUrl($imageUrl))
                    ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
                    ->values()
                    ->all();

                // Playwright saw the page actually rendered - a static fetch
                // of the same URL sees only what shipped in the initial HTML,
                // nothing for a JS-rendered specs tab, and an access-gate page
                // for anything that outright needed a browser to pass. Prefer
                // it over the static extract whenever Playwright produced one.
                $browserSpecificationText = $this->browser->lastSpecificationText();
                $browserPageUrl = $this->browser->lastProductPageUrl();
                $browserIdentity = $this->browser->lastProductIdentity();
                $browserObservation = $this->browser->exportObservation();

                if ($browserObservation !== []) {
                    // The trainer and final acceptance must see the same
                    // rendered title/H1 as well as structured identifiers.
                    // JSON-LD/og:title alone can omit the exact model code.
                    $renderedSource = ProductPageIdentityEvidence::fromBrowserObservation($browserUrl, $browserObservation);
                    $browserUrl = $renderedSource['url'];
                    $sourceTitle = $renderedSource['title'];
                    $identityEvidence = $renderedSource['_preflight_identity_evidence'];
                    $specificationText = is_string($browserSpecificationText) ? $browserSpecificationText : '';
                } elseif ((is_string($browserPageUrl) && $browserPageUrl !== '') || $browserIdentity !== null) {
                    // The rendered page may disagree with HTTP/search even
                    // when training returns no images. Never let that older
                    // title/SKU identify the new page or rescue its seed URLs.
                    if (is_string($browserPageUrl) && $browserPageUrl !== '') {
                        $browserUrl = $browserPageUrl;
                    }
                    $sourceTitle = is_string($browserIdentity['name'] ?? null)
                        ? $browserIdentity['name'] : null;
                    $specificationText = is_string($browserSpecificationText) ? $browserSpecificationText : '';
                    $identityEvidence = implode("\n", array_filter([
                        $browserIdentity !== null
                            ? (string) json_encode($browserIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            : '',
                        $specificationText,
                    ]));
                }

                if (is_string($browserSpecificationText) && $browserSpecificationText !== '') {
                    $specificationText = $browserSpecificationText;
                }

                $assessment = $this->browser->lastSourceIdentityAssessment();
                if (is_array($assessment)
                    && isset($source[ProductRequestContext::SOURCE_KEY], $assessment['product_draft_id'])
                    && (int) $assessment['product_draft_id'] === (int) $source[ProductRequestContext::SOURCE_KEY]
                    && ($assessment['telegram_update_id'] ?? null) === $telegramUpdateId
                    && is_array($assessment['source'] ?? null)) {
                    $sourceIdentityAssessment = $assessment;
                    if (($assessment['verdict'] ?? null) === 'conflicting') {
                        // A smaller later snapshot cannot undo an observed conflict.
                        $assessedSource = $assessment['source'];
                        $browserUrl = is_string($assessedSource['url'] ?? null) ? $assessedSource['url'] : $browserUrl;
                        $sourceTitle = is_string($assessedSource['title'] ?? null) ? $assessedSource['title'] : null;
                        $identityEvidence = (string) ($assessedSource['_preflight_identity_evidence'] ?? '');
                    }
                }

                if ($browserImages !== []) {
                    $accessGateDetected = false;
                    $failureKind = null;
                    $previousSourceImages = array_slice($images, 0, $sourceImageStart);
                    // Browser output is preferred, but the full-resolution
                    // attributes already present in HTML remain a safety net.
                    // A partial or broken recipe must never erase usable
                    // static originals collected before Playwright started.
                    $images = [...$previousSourceImages, ...$browserImages, ...$staticSourceImages];
                }
            }

            if ($specificationText !== '') {
                $this->specificationTextByPageUrl[ImageUrls::normalizeCandidateUrl($sourceUrl)] = $specificationText;
                $this->specificationTextByPageUrl[ImageUrls::normalizeCandidateUrl($browserUrl)] = $specificationText;
            }

            if ($accessGateDetected && $browserImages === []) {
                $debug?->__invoke('blocked', 'HTTP-запрос получил служебную страницу, и Playwright не смог открыть полноценную карточку товара.');
            }

            $images = collect($images)
                ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
                ->values()->all();
            $sourceImageCount = max(0, count($images) - $sourceImageStart);
            // A record matched by its code keeps its address and evidence through
            // the page look; the page adds observations (SourceIdentityProvenance).
            $pageUrl = SourceIdentityProvenance::pageUrl($source, $browserUrl);
            $pageContext = [
                'url' => $pageUrl,
                'original_url' => $sourceUrl,
                'title' => $sourceTitle ?? (SourceIdentityProvenance::confirmedByCode($source) ? ($source['title'] ?? null) : null),
                '_preflight_final_url' => $pageUrl,
                '_preflight_identity_evidence' => SourceIdentityProvenance::mergeEvidence($source, $identityEvidence),
                '_preflight_specification_text' => $specificationText,
                ...SourceIdentityProvenance::carry($source),
                ...($sourceIdentityAssessment !== null ? ['_observed_identity' => $sourceIdentityAssessment] : []),
                ...(is_array($sourceIdentityAssessment['_photo_applicability'] ?? null)
                    ? ['_photo_applicability' => $sourceIdentityAssessment['_photo_applicability']] : []),
            ];
            $this->sourceContextsByPageUrl[ImageUrls::normalizeCandidateUrl($sourceUrl)] = $pageContext;
            $this->sourceContextsByPageUrl[ImageUrls::normalizeCandidateUrl($browserUrl)] = $pageContext;
            foreach (array_slice($images, $sourceImageStart) as $imageUrl) {
                $this->sourceContextsByImageUrl[ImageUrls::normalizeCandidateUrl($imageUrl)] = $pageContext;
            }
            if ($browserImages !== []) {
                $this->metrics->recordExtraction($sourceUrl, count($browserImages));
            } elseif ($sourceImageCount === 0) {
                $this->metrics->recordExtraction($sourceUrl, 0, $failureKind ?: 'empty_gallery');
            }
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_url' => $sourceUrl,
                'actor' => 'html_resolver',
                'phase' => 'source_resolution',
                'action' => 'extract_page_images',
                'status' => $sourceImageCount > 0 ? 'completed' : 'failed',
                'decision' => $failureKind ?: ($browserImages !== [] ? 'playwright_images' : 'static_images'),
                'input' => [
                    'final_url' => $browserUrl,
                    'browser_mode' => $browserMode,
                    'active_recipe_only' => $activeRecipeOnly,
                ],
                'output' => [
                    'static_count' => count($staticSourceImages),
                    'browser_count' => count($browserImages),
                    'returned_count' => $sourceImageCount,
                ],
            ]);

            if (count($images) >= $limit) {
                break;
            }
        }

        // Publication limits must not discard already verified gallery frames
        // before download and language checks. Keep the old cap for raw URLs.
        return array_values(array_filter($images, fn (string $url, int $index): bool => $index < $limit || $this->isConfirmedGalleryImage($url) || $this->isPartialGalleryImage($url),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function looksLikeNonHtmlDocumentUrl(string $url): bool
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', '7z', 'csv', 'xml', 'json',
        ], true);
    }

    private function isHtmlResponse(mixed $response): bool
    {
        $body = (string) $response->body();
        $prefix = strtolower(ltrim(substr($body, 0, 1024), "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20"));

        if (str_starts_with($prefix, '%pdf-')
            || str_starts_with($prefix, '{"')
            || str_starts_with($prefix, '[')
            || str_starts_with($prefix, 'pk'."\x03\x04")) {
            return false;
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml')) {
            return true;
        }

        if ($contentType !== ''
            && ! str_contains($contentType, 'application/octet-stream')
            && ! str_contains($contentType, 'text/plain')) {
            return false;
        }

        return preg_match('/^\s*(?:<!doctype\s+html|<html\b|<head\b|<body\b)/i', $prefix) === 1;
    }

    /**
     * @return array{bytes: string, source_url: string, mime_type: string, width: int, height: int, confirmed_gallery: bool, partial_gallery: bool}|null
     */
    public function download(string $url, int $maxBytes = 0, ?string &$failureReason = null, ?string $refererUrl = null): ?array
    {
        $maxBytes = $maxBytes > 0 ? $maxBytes : (int) config('product-images.http.image_max_bytes', 26_214_400);
        if (! $this->isPublicUrl($url)) {
            $failureReason = 'not_public_url';

            return null;
        }

        if ($download = $this->alreadyDownloaded($url, $refererUrl, $maxBytes)) {
            return $download;
        }

        try {
            [$response, $finalUrl] = $this->fetch($url, $refererUrl, asDocument: false, maxBytes: $maxBytes,
                timeout: (int) config('product-images.http.image_timeout', 25));

            return $this->imageFromBody($response->body(), $url, $finalUrl, $maxBytes, $failureReason);
        } catch (Throwable $exception) {
            $failureReason = $this->unavailable($url, $exception);

            return null;
        }
    }

    /**
     * download() for several URLs at once - same guards, with the transfers
     * running side by side. One by one, a ten-frame Icecat gallery of 10 MB
     * originals took 17 seconds a frame and live #661 kept four of them.
     *
     * @param  array<int, string>  $urls
     * @param  array<string, ?string>  $refererUrls  page each image was seen on, by URL
     * @return array<string, array{download: ?array, failure: ?string}>
     */
    public function downloadMany(array $urls, array $refererUrls = [], int $maxBytes = 0): array
    {
        $maxBytes = $maxBytes > 0 ? $maxBytes : (int) config('product-images.http.image_max_bytes', 26_214_400);
        $results = [];
        $requests = [];
        foreach (array_unique($urls) as $url) {
            $refererUrl = $refererUrls[$url] ?? null;
            if (! $this->isPublicUrl($url)) {
                $results[$url] = ['download' => null, 'failure' => 'not_public_url'];
            } elseif ($download = $this->alreadyDownloaded($url, $refererUrl, $maxBytes)) {
                $results[$url] = ['download' => $download, 'failure' => null];
            } else {
                $requests[$url] = ['url' => $url, 'headers' => $this->requestHeaders($url, $refererUrl, asDocument: false)];
            }
        }
        $responses = $requests === [] ? []
            : $this->http->getMany($requests, $maxBytes, (int) config('product-images.http.image_timeout', 25));

        foreach (array_keys($requests) as $url) {
            $failure = null;
            $response = $responses[$url] ?? null;
            if ($response instanceof Response && $response->redirect()) {
                // Each hop of a redirect is checked on its own, so chains go one by one.
                $download = $this->download($url, $maxBytes, $failure, $refererUrls[$url] ?? null);
            } elseif ($response instanceof Response) {
                $download = $this->imageFromBody($response->body(), $url, $url, $maxBytes, $failure);
            } else {
                $download = null;
                $failure = $this->unavailable($url, $response ?? new \RuntimeException('Product source download failed.'));
            }
            $results[$url] = ['download' => $download, 'failure' => $failure];
        }

        return $results;
    }

    /**
     * How many photos one downloadMany() call may take. Bandwidth is not what
     * limits it - ten Icecat originals side by side took about as long as four
     * - memory is: each photo is held whole until it is checked, so this is
     * what the worker's free memory holds at the largest allowed photo, after
     * the $reservedBytes the caller still needs for itself.
     */
    public static function concurrentDownloads(int $reservedBytes = 0): int
    {
        if (($configured = (int) config('product-images.http.download_concurrency', 0)) > 0) {
            return $configured;
        }
        $raw = strtolower(trim((string) ini_get('memory_limit')));
        $limit = (int) $raw * match (substr($raw, -1)) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $perPhoto = max(1, (int) config('product-images.http.image_max_bytes', 26_214_400));
        $fits = $limit <= 0 ? PHP_INT_MAX : intdiv(max(0, $limit - memory_get_usage(true) - $reservedBytes), $perPhoto);

        // A pass never needs more at once than the candidates it takes.
        return max(1, min((int) config('product-images.download_candidates', 10), $fits));
    }

    private function alreadyDownloaded(string $url, ?string $refererUrl, int $maxBytes): ?array
    {
        if ($download = $this->galleryDownloads->get($url, $refererUrl, $maxBytes)) {
            return [...$download,
                'confirmed_gallery' => $this->browser->isConfirmedGalleryImage($url),
                'partial_gallery' => $this->browser->isPartialGalleryImage($url),
            ];
        }

        if ($transferred = $this->transfers->get($url, $maxBytes)) {
            return [
                ...$transferred,
                'confirmed_gallery' => $this->browser->isConfirmedGalleryImage($url)
                    || $this->browser->isConfirmedGalleryImage($transferred['source_url']),
                'partial_gallery' => $this->browser->isPartialGalleryImage($url)
                    || $this->browser->isPartialGalleryImage($transferred['source_url']),
            ];
        }

        return null;
    }

    private function imageFromBody(string $bytes, string $url, string $finalUrl, int $maxBytes, ?string &$failureReason): ?array
    {
        if ($bytes === '') {
            $failureReason = 'empty_response';

            return null;
        }

        if (strlen($bytes) > $maxBytes) {
            $failureReason = 'exceeds_max_bytes';

            return null;
        }

        $dimensions = @getimagesizefromstring($bytes);

        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
            $failureReason = 'not_a_decodable_image';

            return null;
        }

        return [
            'bytes' => $bytes,
            'source_url' => $finalUrl,
            'mime_type' => $dimensions['mime'] ?? 'application/octet-stream',
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
            'confirmed_gallery' => $this->browser->isConfirmedGalleryImage($url)
                || $this->browser->isConfirmedGalleryImage($finalUrl),
            'partial_gallery' => $this->browser->isPartialGalleryImage($url)
                || $this->browser->isPartialGalleryImage($finalUrl),
        ];
    }

    private function unavailable(string $url, Throwable $exception): string
    {
        Log::notice('Product image candidate was unavailable.', [
            'host' => parse_url($url, PHP_URL_HOST),
            'error' => $exception->getMessage(),
        ]);

        return mb_substr($exception->getMessage(), 0, 200);
    }

    public function isConfirmedGalleryImage(string $url): bool
    {
        return $this->browser->isConfirmedGalleryImage($url);
    }

    public function rememberTrainingDownload(string $url, string $pageUrl, array $download): void
    {
        $this->galleryDownloads->remember($url, $pageUrl, $download);
    }

    public function isPartialGalleryImage(string $url): bool
    {
        return $this->browser->isPartialGalleryImage($url);
    }

    /** @return array{Response, string} */
    /**
     * A bot filter scores the whole header set, not the User-Agent alone. This
     * request already claimed to be Chrome while sending one hybrid Accept for
     * both pages and images and none of the Sec-* headers every real Chrome
     * emits - a combination no browser produces. Sending what the claimed
     * browser would actually send for this kind of resource removes that
     * particular tell. It does not defeat a genuine challenge page: once a WAF
     * has decided to challenge, only a real browser session gets through, which
     * is what the Playwright path is for.
     *
     * @return array<string, string>
     */
    private function browserHeaders(bool $asDocument): array
    {
        $common = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Sec-Ch-Ua' => '"Not)A;Brand";v="8", "Chromium";v="138", "Google Chrome";v="138"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
        ];

        return $asDocument
            ? [
                ...$common,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
            ]
            : [
                ...$common,
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Sec-Fetch-Dest' => 'image',
                'Sec-Fetch-Mode' => 'no-cors',
                'Sec-Fetch-Site' => 'cross-site',
            ];
    }

    /**
     * $confineToHost, when given, is checked before EVERY request this
     * makes - the first one and every redirect hop after it - not only
     * compared against wherever the chain finally ends up: a caller relying
     * only on a start-vs-end comparison would accept allowed-host ->
     * other-host -> back-to-allowed-host, three real requests to a host it
     * never approved, none of them visible in that comparison.
     */
    private function fetch(
        string $url,
        ?string $refererUrl = null,
        bool $asDocument = true,
        ?string $confineToHost = null,
        int $maxBytes = 8_388_608,
        ?int $timeout = null,
    ): array {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (! $this->isPublicUrl($url)) {
                throw new \RuntimeException('Blocked non-public product source URL.');
            }

            if ($confineToHost !== null
                && strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower($confineToHost)) {
                throw new ProductSourceHostDriftException($url);
            }

            // Many CDNs gate images by Referer against the *page* the photo
            // was seen on (e.g. bhphotovideo.com), not the asset host itself
            // (static.bhphoto.com) - a browser loading the product page sends
            // the page's own URL as Referer, never the image origin. Falling
            // back to the image's own origin when no page URL is known keeps
            // prior behavior for callers that never had one to pass.
            $response = $this->http->get($url, $this->requestHeaders($url, $refererUrl, $asDocument), $maxBytes, $timeout);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    $response->throw();
                }

                $url = $this->absoluteUrl($location, $url);

                continue;
            }

            $response->throw();

            return [$response, $url];
        }

        throw new \RuntimeException('Too many redirects while resolving product image.');
    }

    /** @return array<string, string> */
    private function requestHeaders(string $url, ?string $refererUrl, bool $asDocument): array
    {
        return [
            ...$this->browserHeaders($asDocument),
            'Referer' => $refererUrl !== null && $refererUrl !== '' ? $refererUrl : $this->origin($url).'/',
            'Cache-Control' => 'no-cache',
        ];
    }

    private function origin(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    private function extractPageIdentityEvidence(string $html, string $pageUrl): string
    {
        $metadata = ProductSourcePageMetadata::fromHtml($html, $pageUrl);
        // A compatible-products widget can mention the requested laptop on
        // a warranty/service page. Only the page's own product is identity
        // evidence; the larger specification extract remains available to AI.
        $values = [$pageUrl, ...($metadata['titles'] ?? [])];
        foreach ($metadata['product'] ?? [] as $key => $value) {
            $values[] = $key.': '.$value;
        }

        return Str::limit(Str::squish(implode(' ', array_unique($values))), 2500, '');
    }

    /**
     * A far larger, specifications-oriented extract than
     * extractPageIdentityEvidence()'s ~2500-character identity fingerprint -
     * that one only ever captures title/h1/meta/JSON-LD identifiers, which
     * rarely mention RAM/GPU/storage at all. Built once, while the page is
     * already open, and persisted by the caller (see
     * ProductSourcePageEvidence) so the later specification-reconciliation
     * step never needs to re-fetch this same page itself - a fresh plain
     * HTTP request at that later point would only ever see whatever a
     * static fetch already saw here (or an access-gate page for anything
     * that actually required Playwright), so re-fetching then could never
     * recover more than capturing now already does.
     */
    private function extractPageSpecificationText(string $html, string $pageUrl): string
    {
        if ($html === '' || strlen($html) > 5_000_000) {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        // application/ld+json scripts are excluded here specifically -
        // real bug (2026-09-11): removing every <script> first and only then
        // querying //script[@type="application/ld+json"] for structured
        // data left nothing to find; the tag has to survive this pass to be
        // read below.
        foreach (['//script[not(@type="application/ld+json")]', '//style', '//noscript', '//nav', '//footer', '//header'] as $query) {
            $xpath = new DOMXPath($document);

            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $xpath = new DOMXPath($document);
        $parts = [];

        // One line per row, "label: value". textContent glued the cells into
        // "Installed RAM16 GB", and card assembly - which must quote this text
        // verbatim - could only quote the glue: gpt-6-luna quoted the table it
        // could read, "Processor Type: Core Ultra 7", and every attempt was
        // refused as not observed (request #99).
        foreach ($xpath->query('//table') ?: [] as $table) {
            $rows = [];

            foreach ($xpath->query('.//tr', $table) ?: [] as $row) {
                $cells = collect(iterator_to_array($xpath->query('./th | ./td', $row) ?: []))
                    ->map(fn (\DOMNode $cell): string => $this->cellText($cell))
                    ->filter(fn (string $text): bool => $text !== '')
                    ->values();

                if ($cells->isNotEmpty()) {
                    $rows[] = $cells->implode($cells->count() === 2 ? ': ' : ' | ');
                }
            }

            if ($rows !== []) {
                $parts[] = implode("\n", $rows);
            }
        }

        foreach ($xpath->query('//dl') ?: [] as $list) {
            $lines = [];
            $term = null;

            foreach ($xpath->query('./dt | ./dd | ./div/dt | ./div/dd', $list) ?: [] as $item) {
                $text = $this->cellText($item);

                if (strtolower($item->nodeName) === 'dt') {
                    $term = $text;
                } elseif ($text !== '') {
                    $lines[] = $term !== null && $term !== '' ? "{$term}: {$text}" : $text;
                }
            }

            if ($lines !== []) {
                $parts[] = implode("\n", $lines);
            }
        }

        foreach ($xpath->query('//*[@itemprop]') ?: [] as $node) {
            $name = $node->attributes?->getNamedItem('itemprop')?->nodeValue;
            $value = trim(html_entity_decode(strip_tags($node->nodeValue), ENT_QUOTES | ENT_HTML5));

            if (is_string($name) && $name !== '' && $value !== '') {
                $parts[] = "{$name}: ".Str::squish($value);
            }
        }

        // Structured data as the same "label: value" lines, not raw JSON:
        // {"name":"Processor Type","propertyID":"processor_type","value":"Core
        // Ultra 7"} was the only form of pcnation.com's specification table.
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode($node->nodeValue ?? '', true);

            if (is_array($decoded)) {
                $lines = [];
                $this->structuredDataLines($decoded, $lines);
                if ($lines !== []) {
                    $parts[] = implode("\n", $lines);
                }
            }
        }

        // Catch-all for div-based spec layouts the selectors above miss -
        // only when they found nothing at all, not appended alongside them:
        // a real spec table's text would otherwise be sent to the AI twice,
        // wasting a good chunk of the size budget on a duplicate.
        if ($parts === []) {
            $body = $xpath->query('//body')?->item(0);
            $parts[] = $body !== null ? $this->blockText($body) : '';
        }

        $text = implode("\n", array_filter(array_map(
            fn (string $part): string => trim((string) preg_replace(['/[^\S\n]+/u', '/\n\s*\n+/u'], [' ', "\n"], $part)),
            $parts,
        )));

        return Str::limit($text, 12_000, '');
    }

    /** One table cell or list item as a single readable line. */
    private function cellText(\DOMNode $node): string
    {
        return trim(Str::squish(html_entity_decode($this->blockText($node), ENT_QUOTES | ENT_HTML5)));
    }

    /** Text with a line break wherever the page starts a new block, instead of textContent's glue. */
    private function blockText(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        $text = '';
        foreach ($node->childNodes ?? [] as $child) {
            $text .= $this->blockText($child);
        }

        $block = in_array(strtolower($node->nodeName), [
            'p', 'div', 'li', 'tr', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article',
            'table', 'ul', 'ol', 'dl', 'dt', 'dd', 'td', 'th',
        ], true);

        return $block ? "\n{$text}\n" : $text;
    }

    /**
     * schema.org name/value pairs (PropertyValue) as "name: value", other
     * scalar fields as "field: value". Keep vocabulary IRIs such as
     * schema.org/NewCondition: they are values, not image/navigation links.
     * Paths retain which product/offer owns a value in arrays and @graph.
     *
     * @param  array<int, string>  $lines
     */
    private function structuredDataLines(mixed $node, array &$lines, int $depth = 0, string $path = ''): void
    {
        if (! is_array($node) || $depth > 8) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $index => $item) {
                $this->structuredDataLines($item, $lines, $depth + 1, $path.'['.$index.']');
            }

            return;
        }

        if (is_scalar($node['name'] ?? null) && is_scalar($node['value'] ?? null)) {
            if ($path !== '') {
                $lines[] = '['.$path.']';
            }
            $lines[] = Str::squish($node['name'].': '.$node['value'].(is_scalar($node['unitText'] ?? null) ? ' '.$node['unitText'] : ''));

            return;
        }

        foreach ($node as $key => $value) {
            $field = $path === '' ? (string) $key : $path.'.'.$key;
            if ($key === '@graph') {
                $this->structuredDataLines($value, $lines, $depth + 1, $field);
            } elseif ($key === '@id' && is_string($value) && trim($value) !== '') {
                // JSON-LD node references also carry relationships to a specific
                // product. Never flatten them into an unrelated offer's fields.
                $lines[] = ($path !== '' && count($node) === 1 ? $path : $field).': '.Str::squish($value);
            } elseif (is_string($key) && ! str_starts_with($key, '@')) {
                if (is_scalar($value) && trim((string) $value) !== ''
                    && (! preg_match('#^https?://#i', (string) $value)
                        || in_array(strtolower((string) parse_url((string) $value, PHP_URL_HOST)), ['schema.org', 'www.schema.org'], true))) {
                    $lines[] = Str::squish($field.': '.html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));
                } elseif (is_array($value)) {
                    $this->structuredDataLines($value, $lines, $depth + 1, $field);
                }
            }
        }
    }

    /** @return array<int, string> */
    private function extractPageImages(string $html, string $pageUrl): array
    {
        if ($html === '' || strlen($html) > 5_000_000) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $galleryScope = '//*[contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "gallery") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "slider") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "swiper") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "carousel") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product-image") or contains(translate(concat(" ", @class, " ", @id, " "), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "product-media")]';
        $directQueries = [
            $galleryScope.'//*[@data-big]/@data-big',
            $galleryScope.'//*[@data-old-hires]/@data-old-hires',
            $galleryScope.'//img/@data-zoom-image',
            $galleryScope.'//img/@data-large_image',
            $galleryScope.'//img/@data-full',
            $galleryScope.'//img/@data-full-src',
            $galleryScope.'//img/@data-hires',
            $galleryScope.'//img/@data-original',
            $galleryScope.'//img/@data-image',
            $galleryScope.'//img/@data-src',
            $galleryScope.'//source/@src',
            $galleryScope.'//img/@src',
            '//meta[@property="og:image"]/@content',
            '//meta[@property="og:image:url"]/@content',
            '//meta[@property="og:image:secure_url"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//meta[@name="twitter:image:src"]/@content',
            '//meta[@itemprop="image"]/@content',
            '//link[@rel="image_src"]/@href',
            '//*[@data-big]/@data-big',
            '//*[@data-old-hires]/@data-old-hires',
            '//img/@data-full',
            '//img/@data-full-src',
            '//img/@data-hires',
            '//img/@data-image',
            '//img/@data-zoom-image',
            '//img/@data-large_image',
            '//img/@data-original',
            '//img/@data-lazy-src',
            '//img/@data-src',
            '//img/@src',
            '//source/@src',
        ];
        $srcsetQueries = [
            $galleryScope.'//img/@srcset',
            $galleryScope.'//img/@data-srcset',
            $galleryScope.'//source/@srcset',
            '//img/@srcset',
            '//img/@data-srcset',
            '//source/@srcset',
        ];
        $images = [];

        foreach ($directQueries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $candidate = trim(html_entity_decode($node->nodeValue, ENT_QUOTES | ENT_HTML5));

                if ($candidate !== '') {
                    $images[] = $candidate;
                }
            }
        }

        foreach ($srcsetQueries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $images = [...$images, ...$this->srcsetUrls($node->nodeValue)];
            }
        }

        // A thumbnail button whose full-resolution URL only ever appears as a
        // quoted argument to an inline onclick image-swap handler (real case:
        // onclick="window.changeMainImage('.../02.png', this)") is invisible
        // to every attribute query above - none of them look inside onclick.
        // This does not depend on the handler's name or this one site: any
        // quoted, image-extension-ending string inside an onclick is a
        // legitimate candidate URL by the same logic as srcset or data-src.
        foreach ($xpath->query('//*/@onclick') ?: [] as $node) {
            if (preg_match_all(
                '/[\'"]([^\'"\s]+\.(?:jpe?g|png|webp|gif|avif)(?:\?[^\'"\s]*)?)[\'"]/i',
                html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5),
                $onclickMatches,
            ) > 0) {
                $images = [...$images, ...$onclickMatches[1]];
            }
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $structuredData = json_decode(html_entity_decode($node->nodeValue, ENT_QUOTES | ENT_HTML5), true);

            if (is_array($structuredData)) {
                $this->collectStructuredImageUrls($structuredData, $images);
            }
        }

        foreach ($xpath->query('//script[not(@type) or @type="application/json" or @type="text/javascript" or @type="application/javascript"]') ?: [] as $node) {
            $images = [...$images, ...$this->scriptImageUrls($node->nodeValue)];
        }

        return collect($images)
            ->map(fn (string $candidate): ?string => $this->normalizeImageCandidate($candidate, $pageUrl))
            ->filter()
            // Two different query-string renditions of one CDN photo (Scene7
            // wid/hei, $PRESET$, ASUS w48/64/96/184, ...) are not distinct
            // photos - collapsing them here, at the root of every page scrape,
            // keeps every downstream count and gallery-sufficiency decision
            // from inheriting an inflated "distinct photo" count.
            ->map(fn (string $url): string => ImageUrls::normalizeCandidateUrl($url))
            ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
            ->take((int) config('product-images.max_urls_per_page', 60))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function srcsetUrls(string $srcset): array
    {
        $urls = [];

        foreach (array_reverse(explode(',', $srcset)) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? null;

            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** @param array<int|string, mixed> $value @param array<int, string> $images */
    private function collectStructuredImageUrls(array $value, array &$images, ?string $parentKey = null): void
    {
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? strtolower($key) : $parentKey;

            if (
                is_string($item)
                && (
                    in_array($normalizedKey, ['image', 'contenturl', 'thumbnailurl'], true)
                    || ($parentKey === 'image' && in_array($normalizedKey, ['url', 'src'], true))
                )
            ) {
                $images[] = $item;
            } elseif (is_array($item)) {
                $this->collectStructuredImageUrls($item, $images, $normalizedKey);
            }
        }
    }

    /** @return array<int, string> */
    private function scriptImageUrls(string $script): array
    {
        if ($script === '' || strlen($script) > 2_000_000) {
            return [];
        }

        $decoded = str_replace(['\\/', '\u002F'], '/', html_entity_decode($script, ENT_QUOTES | ENT_HTML5));
        preg_match_all(
            "#https?:\\\\?/\\\\?/[^\"'<>\s\\\\]+?\\.(?:jpe?g|png|webp|avif)(?:\\?[^\"'<>\s\\\\]*)?#i",
            $decoded,
            $matches,
        );

        return collect($matches[0] ?? [])
            ->map(fn (string $url): string => str_replace(['\/', '\\/'], '/', $url))
            ->filter(fn (string $url): bool => $this->looksLikeImageUrl($url))
            ->unique(fn (string $url): string => ImageUrls::imageAssetKey($url))
            ->values()
            ->all();
    }

    private function looksLikeImageUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1
            && preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', $url) === 1
            && ! ImageUrlHeuristics::containsMarker($url, [
                ...ImageUrlHeuristics::COMMON_MARKERS,
                ...ImageUrlHeuristics::TRACKING_MARKERS,
            ]);
    }

    private function normalizeImageCandidate(string $candidate, string $pageUrl): ?string
    {
        $candidate = trim(str_replace('\\/', '/', html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5)));
        $lower = strtolower($candidate);

        if (
            $candidate === ''
            || str_starts_with($candidate, '#')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'blob:')
            || ImageUrlHeuristics::containsMarker($candidate, [
                ...ImageUrlHeuristics::COMMON_MARKERS,
                ...ImageUrlHeuristics::THUMBNAIL_MARKERS,
                ...ImageUrlHeuristics::TRACKING_MARKERS,
            ])
        ) {
            return null;
        }

        return $this->absoluteUrl($candidate, $pageUrl);
    }

    private function looksLikeAccessGate(string $html): bool
    {
        $text = Str::lower(strip_tags($html));

        return Str::contains($text, [
            'click the button below to continue shopping',
            'captcha page',
            'enter the characters you see below',
            'sorry, we just need to make sure you\'re not a robot',
            'robot check',
            'access denied',
        ]);
    }

    private function absoluteUrl(string $candidate, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }

        if (str_starts_with($candidate, '/')) {
            return "{$scheme}://{$host}{$port}{$candidate}";
        }

        $path = $base['path'] ?? '/';
        $directory = str_ends_with($path, '/') ? $path : dirname($path).'/';

        return "{$scheme}://{$host}{$port}{$directory}{$candidate}";
    }

    private function isPublicUrl(string $url): bool
    {
        return $this->http->isPublicUrl($url);
    }
}
