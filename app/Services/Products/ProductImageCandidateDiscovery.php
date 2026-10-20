<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Photos\ImageUrls;
use App\Services\ProductSearch\Discovery\DiscoveryRecoveryState;
use App\Services\ProductSearch\Discovery\DiscoveryResponseParser;
use App\Services\ProductSearch\Discovery\InvalidDiscoveryResponse;
use App\Services\ProductSearch\Recovery\RecoveryContext;
use App\Services\ProductSearch\Research\InvalidResearchResponse;
use App\Services\ProductSearch\Research\ResearchSourceRoles;
use App\Services\ProductSearch\SearchJournal;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Throwable;

class ProductImageCandidateDiscovery
{
    /** @var array<string, string> */
    private array $sourcePagesByImageUrl = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceContextsByImageUrl = [];

    private bool $terminalFailure = false;

    /** Unopened pages awaiting the caller's verdict on the previous gallery. */
    private array $pendingSources = [];

    private ?string $pendingSearchKey = null;

    private bool $yieldedGallery = false;

    public function __construct(
        private readonly ProductImageResolver $resolver,
        private readonly WikimediaImageSearch $wikimedia,
        private readonly ProductSourcePriority $sourcePriority,
        private readonly AiErrorPresenter $errors,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly ProductSourceIdentityJudge $identityJudge,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly DiscoveryResponseParser $responseParser,
        private readonly DiscoveryRecoveryState $recovery,
    ) {}

    public function sourcePageForImage(string $imageUrl): ?string
    {
        return $this->sourcePagesByImageUrl[ImageUrls::normalizeCandidateUrl($imageUrl)] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function sourceContextForImage(string $imageUrl): ?array
    {
        return $this->sourceContextsByImageUrl[ImageUrls::normalizeCandidateUrl($imageUrl)] ?? null;
    }

    public function hasTerminalFailure(): bool
    {
        return $this->terminalFailure;
    }

    /** @return array<int, string> */
    /** @param null|callable(string): void $progress */
    public function find(
        ProductDraft $draft,
        array $excludedUrls = [],
        bool $skipKnownSources = false,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $additionalExcludedSourceUrls = [],
        int $searchAttempt = 0,
    ): array {
        // Callers acting long after the draft's original search (retry/top-up
        // buttons pressed minutes or hours later) must pass their own fresh
        // update id here - falling back to the draft's original one would
        // measure time budget and AI-cost attribution against a search that
        // already ended, making canStart() report zero time left immediately.
        $telegramUpdateId ??= $draft->telegram_update_id;
        app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
        $searchKey = $draft->id.':'.$telegramUpdateId;
        $this->recovery->begin($searchKey);
        if ($this->pendingSearchKey !== $searchKey) {
            $this->pendingSources = [];
            $this->pendingSearchKey = $searchKey;
        }
        $this->sourcePagesByImageUrl = [];
        $this->sourceContextsByImageUrl = [];
        $this->terminalFailure = $this->recovery->stopped();
        $this->yieldedGallery = false;
        $excludedUrls = collect($excludedUrls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $knownDraftSourceUrls = $skipKnownSources
            ? collect($draft->sources ?? [])
                ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['url'] ?? null))
                ->pluck('url')
                ->all()
            : [];
        $pendingExcludedSourceUrls = collect([
            ...($draft->excluded_gallery_source_urls ?? []),
            ...$additionalExcludedSourceUrls,
            ...array_map(fn (string $domain): string => 'https://'.$domain, $this->sourcePriority->blockedDomains()),
        ])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();
        $excludedSourceUrls = array_values(array_unique([...$pendingExcludedSourceUrls, ...$knownDraftSourceUrls]));
        if ($this->pendingSources !== []) {
            $pending = array_values(array_filter(
                $this->pendingSources,
                fn (array $source): bool => ! $this->pendingSourceExcluded((string) ($source['url'] ?? ''), $pendingExcludedSourceUrls),
            ));
            $this->pendingSources = [];
            if ($pending !== []) {
                // No new paid Web Search until its already found pages have
                // had a chance. The caller still performs download and Vision.
                return $this->withoutExcluded(
                    $this->resolveSourcesIndividually($pending, $draft, $progress, $telegramUpdateId, sourcesAlreadyQueued: true),
                    $excludedUrls,
                );
            }
        }
        $sources = array_values(array_filter(
            $this->imageSources($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand)),
            fn (array $source): bool => ! $this->sourceExcluded((string) ($source['url'] ?? ''), $excludedSourceUrls),
        ));
        $knownSourceUrls = $skipKnownSources
            ? []
            : $this->resolveSourcesIndividually($sources, $draft, telegramUpdateId: $telegramUpdateId);
        $preferredUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            $knownSourceUrls,
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $directCatalogUrls = array_values(array_filter(
            $preferredUrls,
            fn (string $url): bool => $this->looksLikeCatalogImage($url),
        ));

        if ($this->yieldedGallery) {
            return $preferredUrls;
        }

        if (count($directCatalogUrls) >= (int) config('product-images.public_source_target', 6)) {
            return $directCatalogUrls;
        }

        $wikimediaUrls = $this->wikimedia->find($draft, 10);
        $availableUrls = $this->withoutExcluded($this->sourcePriority->sortUrls(
            [...$preferredUrls, ...$wikimediaUrls],
            $draft->brand,
            $sources,
        ), $excludedUrls);

        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);

        if ($this->recovery->stopped()) {
            return $availableUrls;
        }

        if (! $timeBudget->canStart($telegramUpdateId, 30)) {
            $progress?->__invoke('Резерв времени достигнут: дополнительный AI-поиск источников пропущен, сохраняю уже полученный результат.');

            return $availableUrls;
        }

        $discoveryTimeout = $timeBudget->timeoutFor(
            $telegramUpdateId,
            $settings->imageDiscoveryTimeoutSeconds(),
        );
        $provider = $settings->providerFor('product_image_discovery');
        $model = $settings->modelFor('product_image_discovery');
        $prompt = $this->prompt($draft, $excludedUrls, $excludedSourceUrls, $searchAttempt, $telegramUpdateId);
        // History changes after an answer; that must not invalidate delivery
        // retries of the same pass. A new autonomous pass has a new attempt id.
        $cacheKey = hash('sha256', json_encode([
            $draft->id, $draft->title, $draft->brand, $draft->model, $draft->color,
            $draft->specifications, $draft->primary_source_url, $draft->gallery_search_stop_reason,
            $excludedUrls, $excludedSourceUrls, $searchAttempt, $this->recovery->promptFeedback(),
            data_get(TelegramUpdate::query()->find($telegramUpdateId)?->payload, '_product_search_started_at'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cached = AiRun::query()
            ->where('telegram_update_id', $telegramUpdateId)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('status', 'completed')
            ->where('activity->discovery_cache_key', $cacheKey)
            ->latest('id')
            ->first();

        if ($cached && is_array($cached->response)) {
            return $this->withoutExcluded($this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($cached->response, $draft, $sources, $progress, $telegramUpdateId, $excludedSourceUrls),
            ], $draft->brand, $sources), $excludedUrls);
        }

        $run = AiRun::query()->create([
            'telegram_update_id' => $telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'activity' => ['stage' => 'image_discovery', 'discovery_cache_key' => $cacheKey],
            'started_at' => now(),
        ]);
        $webSearchItems = [];

        try {
            $response = app(OpenAiHeavyOperationGate::class)->run(
                $provider,
                $discoveryTimeout,
                function () use ($telegramUpdateId, $prompt, $provider, $model, $discoveryTimeout, $settings, &$webSearchItems) {
                    app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);

                    return app(StreamingProductResearch::class)->prompt(
                        ProductImageDiscoveryAgent::make(), $prompt, $provider, $model, $discoveryTimeout,
                        min($discoveryTimeout, $settings->productResearchIdleTimeoutSeconds()),
                        function ($event) use (&$webSearchItems, $telegramUpdateId): void {
                            if ($event instanceof ProviderToolEvent
                                && $event->type === 'web_search_call' && $event->itemId !== '') {
                                $webSearchItems[$event->itemId] = $event->data;
                            }
                            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
                        },
                    );
                },
            );
            // Persist measured usage even when parsing fails. An invalid
            // answer is paid work, not a successful empty result or free retry.
            $run->update([
                'invocation_id' => $response->invocationId,
                'response' => $response->toArray(),
                'usage' => [...$response->usage->toArray(), 'web_search_calls' => count($webSearchItems)],
                'activity' => [
                    'discovery_cache_key' => $cacheKey,
                    'response_text' => mb_substr($response->text, 0, 6000),
                    'provider_meta' => $response->meta->toArray(),
                    'provider_web_search_items' => $webSearchItems,
                ],
            ]);
            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
            $data = $this->responseParser->parse($response->toArray());
            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => [...$response->usage->toArray(), 'web_search_calls' => count($webSearchItems)],
                'completed_at' => now(),
            ]);

            $resolved = $this->withoutExcluded($this->sourcePriority->sortUrls([
                ...$availableUrls,
                ...$this->candidateUrls($data, $draft, $sources, $progress, $telegramUpdateId, $excludedSourceUrls),
            ], $draft->brand, $sources), $excludedUrls);
            $this->recovery->succeeded();

            return $resolved;
        } catch (ProductSearchCancelled $exception) {
            $run->update(['status' => 'cancelled', 'error' => $exception->getMessage(), 'completed_at' => now()]);
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidResearchResponse
                && $exception->usage !== null) {
                $run->update(['usage' => [...$exception->usage, 'web_search_calls' => count($webSearchItems)]]);
            }
            $presented = $this->errors->present($exception);
            if (! $exception instanceof InvalidDiscoveryResponse
                && ($presented['kind'] ?? '') === 'unknown') {
                $recovery = new RecoveryContext('image_discovery', 'operation_failed',
                    mb_substr($exception->getMessage(), 0, 2000),
                    ['web_search', 'return_source_candidates'], ['image_urls' => $availableUrls],
                    ['exception_type' => $exception::class]);
                $presented['retryable'] = $this->recovery->failedOperation($recovery);
                $presented['message'] = $presented['retryable']
                    ? 'Дополнительный поиск прервался; передаю причину агенту для исправления в пределах бюджета.'
                    : 'Попытка исправления повторила сбой без результата; найденное сохранено.';
                $run->update(['activity' => [...($run->activity ?? []), 'recovery' => $recovery->toArray()]]);
            }
            if ($exception instanceof InvalidDiscoveryResponse) {
                $retryable = $this->recovery->failed($exception);
                $presented = ['retryable' => $retryable, 'message' => $retryable
                    ? 'Модель не вернула корректный список источников; передаю причину для исправления ответа.'
                    : 'Модель повторила ту же ошибку ответа без результата; найденное сохранено, одинаковый запрос больше не повторяю.'];
                $run->update(['activity' => [...($run->activity ?? []), 'response_failure' => $exception->reason]]);
            }
            $this->terminalFailure = ! ($presented['retryable'] ?? false);
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            Log::warning('Optional AI product image discovery failed; keeping the current search result.', [
                'draft_id' => $draft->id,
                'available_candidates' => count($availableUrls),
                'error' => $exception->getMessage(),
            ]);
            $progress?->__invoke($this->terminalFailure
                ? 'Дополнительный AI-поиск остановлен: '.($presented['message'] ?? $exception->getMessage())
                : ($exception instanceof InvalidDiscoveryResponse
                    ? $presented['message']
                    : 'Дополнительный AI-поиск временно недоступен; сохраняю текущий результат.'));

            return $availableUrls;
        }
    }

    /** @param array<int, array<string, mixed>> $sources @return array<int, array<string, mixed>> */
    private function imageSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn (array $source): bool => ResearchSourceRoles::maySupplyPhotos($source)
                && $this->looksLikeHtmlProductPage((string) ($source['url'] ?? '')))
            ->values()
            ->all();
    }

    public function looksLikeHtmlProductPage(string $url): bool
    {
        $lower = strtolower(urldecode($url));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($url === '' || preg_match('#\.(?:pdf|docx?|xlsx?|csv|zip)(?:\?|$)#i', $path) === 1) {
            return false;
        }

        // An asset proxy hides its real target inside a base64 path segment, so
        // the extension test above sees nothing: prod.isg.bruneau.media/asset/
        // <base64>/ decodes to icecat.biz/rest/product-pdf, and the search only
        // learned it was a PDF after downloading it. Decoding is general - it
        // costs nothing, names no host, and catches every proxy shaped this way.
        // Base64 is case-sensitive, so this reads the path as written rather
        // than the lower-cased copy the checks below use.
        foreach ($this->proxiedTargets((string) parse_url($url, PHP_URL_PATH)) as $target) {
            if (preg_match('#(?:^|[/\-_.])(?:pdf|docx?|xlsx?)(?:$|[/\-_.?])#i', $target) === 1) {
                return false;
            }
        }

        if (str_contains($host, 'psref.lenovo.com')
            || str_contains($host, 'energystar.gov')
            || str_contains($host, 'support.lenovo.com')
            || str_contains($host, 'pcsupport.lenovo.com')
        ) {
            return false;
        }

        return ! str_contains($lower, '/support/')
            && ! str_contains($lower, '/drivers/')
            && ! str_contains($lower, '/manual')
            && ! str_contains($lower, 'spec.pdf')
            && ! str_contains($lower, 'datasheet')
            && ! str_contains($lower, 'certificate');
    }

    /**
     * URLs a proxy path carries inside base64 segments. Only a segment that
     * decodes to printable text naming an address is returned - random ids and
     * hashes decode to binary and are ignored.
     *
     * @return array<int, string>
     */
    private function proxiedTargets(string $path): array
    {
        $targets = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (strlen($segment) < 16) {
                continue;
            }

            $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

            if ($decoded === false || preg_match('#^[\x20-\x7e]+$#', $decoded) !== 1) {
                continue;
            }

            if (str_contains($decoded, 'http') || str_contains($decoded, '/')) {
                $targets[] = $decoded;
            }
        }

        return $targets;
    }

    private function looksLikeCatalogImage(string $url): bool
    {
        $lower = strtolower(urldecode($url));

        if (ImageUrlHeuristics::containsMarker($lower, [
            ...ImageUrlHeuristics::COMMON_MARKERS,
            ...ImageUrlHeuristics::ASSET_MARKERS,
        ])) {
            return false;
        }

        return preg_match('#\.(?:jpe?g|png|webp|avif)(?:\?|$)#i', $lower) === 1
            || preg_match('#/(?:w[3-9]\d{2,}|h[3-9]\d{2,})(?:\?|/|$)#i', $lower) === 1;
    }

    /** @param array<int, string> $excludedUrls @param array<int, string> $excludedSourceUrls */
    private function prompt(ProductDraft $draft, array $excludedUrls = [], array $excludedSourceUrls = [], int $searchAttempt = 0, ?int $telegramUpdateId = null): string
    {
        $specifications = collect($draft->specifications ?? [])
            ->map(fn (mixed $item): string => is_array($item)
                ? trim(($item['name'] ?? $item['key'] ?? '').': '.($item['value'] ?? ''), ': ')
                : '')
            ->filter()
            ->take(10)
            ->implode('; ');
        $knownPages = collect($this->imageSources($this->sourcePriority->sortSources($draft->sources ?? [], $draft->brand)))
            ->pluck('url')
            ->filter(fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSourceUrls))
            ->take(8)
            ->implode("\n");
        $excluded = collect($excludedUrls)->take(20)->implode("\n");
        $excludedSources = collect($excludedSourceUrls)->take(20)->implode("\n");
        $attemptInstruction = $searchAttempt > 0
            ? "Independent fallback search pass: {$searchAttempt}. Return new sources not excluded above."
            : '';
        $recoveryInstruction = $this->recovery->promptFeedback();
        $originalRequest = ProductRequestContext::forDraft($draft)->originalRequest() ?? '';
        $searchHistory = AiRun::query()->where('telegram_update_id', $telegramUpdateId ?? $draft->telegram_update_id)
            ->where('prompt', 'like', '%[product-image-discovery:%')
            ->whereIn('status', ['completed', 'failed'])->latest('id')->limit(8)->get()
            ->map(fn (AiRun $run): array => [
                'run_id' => $run->id, 'status' => $run->status,
                'queries_tried' => $run->response['queries_tried'] ?? [],
                'returned_page_urls' => collect($run->response['sources'] ?? [])->pluck('page_url')
                    ->merge($run->response['page_urls'] ?? [])->filter()->take(12)->values()->all(),
                'returned_image_count' => collect($run->response['image_urls'] ?? [])->count(),
                'error' => $run->error ? mb_substr($run->error, 0, 350) : null,
            ])->reverse()->values()->all();
        $sourceHistory = ProductSourceAttempt::query()->where('product_draft_id', $draft->id)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'blocked', 'interrupted'])
                    ->orWhereIn('decision', ['blocked', 'http_403', 'unconfirmed_access_gate'])
                    ->orWhere('decision', 'like', 'reject_%');
            })
            ->whereIn('phase', ['gallery_preflight', 'source_selection', 'source_resolution', 'image_verification'])
            ->latest('id')->limit(15)->get(['product_url', 'action', 'status', 'decision'])
            ->map(fn (ProductSourceAttempt $attempt): array => [...$attempt->toArray(),
                'site_name' => self::siteName((string) $attempt->product_url)])
            ->toArray();
        // Every page this search already opened and how it ended, gallery
        // training included. Live #428 trained storefront after storefront of
        // one network sharing one failing media CDN because this journal never
        // showed training outcomes. The agent decides what is worth a visit.
        $runId = $telegramUpdateId ?? $draft->telegram_update_id;
        $pagesOpened = SearchJournal::pagesOpened((int) $runId, $draft);
        // Storefronts of one network (allegro.pl/.cz/.sk, a.bkstr.com/b.bkstr.com)
        // usually fail together; the agent sees that link instead of guessing.
        $networkFailures = collect([
            ...array_map(fn (array $row): array => ['site_name' => $row['site_name'], 'host' => (string) parse_url((string) $row['product_url'], PHP_URL_HOST)], $sourceHistory),
            ...array_map(fn (array $page): array => ['site_name' => $page['site_name'], 'host' => $page['host']],
                array_filter($pagesOpened, fn (array $page): bool => $page['photos_saved'] === 0)),
        ])->groupBy('site_name')
            ->map(fn ($rows): array => ['hosts' => $rows->pluck('host')->unique()->values()->all(), 'failures' => $rows->count()])
            ->filter(fn (array $network, $name): bool => $name !== '' && count($network['hosts']) > 1)->all();
        $refusingHosts = SearchJournal::refusingHosts();
        $searchJournal = json_encode(['previous_search_results' => $searchHistory, 'previous_source_failures' => $sourceHistory,
            'pages_opened_this_search' => $pagesOpened,
            ...($refusingHosts === [] ? [] : ['sites_refusing_us_recently' => $refusingHosts,
                'refusal_note' => 'These sites refused every page fetch in recent searches and never gave a usable image. Prefer other sites unless you have a concrete reason to expect a different result.']),
            ...($networkFailures === [] ? [] : ['failed_site_networks' => $networkFailures,
                'network_note' => 'Several regional domains of the same site_name already failed. A sibling domain of that network usually fails the same way; prefer an independent site unless you have evidence otherwise.'])],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cardRecovery = $draft->reconciliationPending()
            ? ProductSourceAttempt::where('product_draft_id', $draft->id)
                ->where('action', 'recover_with_another_source')->latest('id')->first()
            : null;
        $cardRecoveryInstruction = $cardRecovery ? json_encode([
            'stage' => 'card_assembly_recovery',
            'original_operator_request' => ProductRequestContext::forDraft($draft)->originalRequest(),
            'failed_source_url' => $cardRecovery->product_url,
            'failure_context' => data_get($cardRecovery->output, 'failure_context'),
            'saved_photo_count' => data_get($cardRecovery->output, 'saved_file_count'),
            'next_action' => 'Find another product page that supports a coherent card and gallery. Preserve the operator requirements; an inferred research offer is not an additional operator requirement. Do not retry excluded pages.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        return <<<PROMPT
            [product-image-discovery:v9]
            Find current, downloadable professional catalog image candidates for this exact product:
            Title: {$draft->title}
            Brand: {$draft->brand}
            Exact model: {$draft->model}
            Required color/version: {$draft->color}
            Identifying specifications: {$specifications}
            Original operator requirements: {$originalRequest}
            Already known product pages:
            {$knownPages}
            Previously rejected image URLs that must not be returned again:
            {$excluded}
            Previously rejected source pages/domains that must not be searched again:
            {$excludedSources}
            {$attemptInstruction}
            {$recoveryInstruction}
            {$cardRecoveryInstruction}
            Persistent history from this same search, including earlier queue jobs:
            {$searchJournal}
            pages_opened_this_search shows how every page already opened in this search ended: gallery training
            rounds, photos saved and the gallery agent's own reason for ending the page. Pages of one site_name
            usually share one platform and media delivery; when such a page ended without photos, prefer an
            independent site over another storefront of that network unless you have evidence it differs.
            Empty previous results are feedback: change your search queries, retailer, language or market instead
            of repeating the same unsuccessful approach. Previously blocked sources need a concrete new access
            method before another visit. Requirements stated by the operator remain mandatory. Research-inferred
            regional codes are not extra operator requirements: if another coherent offer satisfies the original
            request, return its exact page for explicit identity validation and whole-card offer adoption; never
            silently label another offer's photos as the current offer. Do not invent missing query history or URLs.

            Search the exact model and required color/version using professional manufacturer, retailer, or marketplace
            product pages. Do not prefer a source by its type; extraction history is ranked by the application. Never use
            photos from individual used-item listings, auctions, reviews, social media, or user uploads. A used/refurbished
            request may use professional photos of the exact same chassis revision and color from a new-retail or
            manufacturer page. Do not use PDF, support/download/manual pages, certifications, generic family pages,
            any rejected source/domain above, or any previously rejected image. Prefer working page URLs from several
            new domains; our server extracts their galleries. Return a direct image URL only when the current search
            result exposes that exact URL. Do not reconstruct or guess CDN paths.
            Return a product's own main page, not its specifications or tech-specs sub-page. Search the exact model
            code across markets and in local languages: retailers in any country (European, Ukrainian, Asian)
            usually carry the same official gallery and open to our reader where large US retailers block it.
            Text inside photos is checked separately; the page's country or language never disqualifies it.
            PROMPT;
    }

    /** @param array<int, string> $excludedSourceUrls */
    /**
     * The site's own name without its public suffix: shop.allegro.pl and
     * allegro.cz are both "allegro", amazon.co.uk is "amazon". A short
     * second-level label under a two-letter country code is treated as part
     * of the suffix. Advisory only, so an imperfect split costs nothing.
     */
    public static function siteName(string $url): string
    {
        $labels = explode('.', strtolower((string) (parse_url($url, PHP_URL_HOST) ?: '')));
        if (count($labels) < 2) {
            return '';
        }
        $tld = array_pop($labels);
        $name = array_pop($labels);
        if ($labels !== [] && strlen($tld) === 2 && strlen($name) <= 3) {
            $name = array_pop($labels);
        }

        return $name;
    }

    private function sourceExcluded(string $url, array $excludedSourceUrls): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($excludedSourceUrls)
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
    }

    /**
     * A failed page does not exhaust other pages already found on its store.
     * Bare domain exclusions still apply to every pending page of that host.
     *
     * @param  array<int, string>  $excludedSourceUrls
     */
    private function pendingSourceExcluded(string $url, array $excludedSourceUrls): bool
    {
        $host = ProductSourcePriority::host($url);
        if ($host === '' || $this->recipeRouter->domainIsBlocked($url)) {
            return true;
        }

        foreach ($excludedSourceUrls as $excluded) {
            if (parse_url($excluded, PHP_URL_HOST) === null) {
                $excluded = 'https://'.$excluded;
            }
            $excludedHost = ProductSourcePriority::host($excluded);
            $excludedPath = rtrim((string) parse_url($excluded, PHP_URL_PATH), '/');
            $excludedQuery = (string) parse_url($excluded, PHP_URL_QUERY);
            if ($excludedPath === '' && $excludedQuery === '') {
                if (ProductSourcePriority::hostsMatch($host, $excludedHost)) {
                    return true;
                }
            } elseif ($host === $excludedHost
                && rtrim((string) parse_url($url, PHP_URL_PATH), '/') === $excludedPath
                && (string) parse_url($url, PHP_URL_QUERY) === $excludedQuery) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $urls @param array<int, string> $excludedUrls @return array<int, string> */
    private function withoutExcluded(array $urls, array $excludedUrls): array
    {
        if ($excludedUrls === []) {
            return array_values(array_unique($urls));
        }

        return array_values(array_diff(array_unique($urls), $excludedUrls));
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function candidateUrls(
        array $data,
        ProductDraft $draft,
        array $sources,
        ?callable $progress = null,
        ?int $telegramUpdateId = null,
        array $excludedSourceUrls = [],
    ): array {
        $excludedSources = array_values(array_unique([
            ...($draft->excluded_gallery_source_urls ?? []),
            ...$excludedSourceUrls,
        ]));
        $rawPairedSources = collect($data['sources'] ?? [])
            ->filter(fn (mixed $source): bool => is_array($source)
                && is_string($source['page_url'] ?? null));
        $pairedSources = $rawPairedSources
            ->filter(fn (array $source): bool => ! $this->sourceExcluded($source['page_url'], $excludedSources))
            ->values();
        $imageUrls = $rawPairedSources->isEmpty()
            ? array_values(array_filter(
                $data['image_urls'] ?? [],
                fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSources),
            ))
            : $pairedSources->flatMap(function (array $source): array {
                $pageUrl = $source['page_url'];

                return collect($source['image_urls'] ?? [])
                    ->filter(fn (mixed $url): bool => is_string($url))
                    ->each(function (string $url) use ($pageUrl): void {
                        $key = ImageUrls::normalizeCandidateUrl($url);
                        $this->sourcePagesByImageUrl[$key] = $pageUrl;
                        $this->sourceContextsByImageUrl[$key] = ['url' => $pageUrl];
                    })
                    ->values()
                    ->all();
            })->unique()->values()->all();
        $pageUrls = array_values(array_filter(
            [
                ...$pairedSources->pluck('page_url')->all(),
                ...($data['page_urls'] ?? []),
            ],
            fn (mixed $url): bool => is_string($url) && ! $this->sourceExcluded($url, $excludedSources),
        ));
        $pageUrls = array_slice($this->sourcePriority->sortUrls($pageUrls, $draft->brand, $sources), 0, (int) config('product-images.ai_page_urls_limit', 4));
        $resolved = $this->resolveSourcesIndividually(
            array_map(fn (string $url): array => ['url' => $url], $pageUrls),
            $draft,
            $progress,
            $telegramUpdateId,
        );

        $sorted = $this->sourcePriority->sortUrls(
            [...$resolved, ...$imageUrls],
            $draft->brand,
            $sources,
        );

        return $this->limitCandidateUrlsBySource($sorted);
    }

    /**
     * Resolve every product page separately so an image never loses the exact
     * card it came from when multiple stores share the same CDN host.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, string>
     */
    private function resolveSourcesIndividually(array $sources, ProductDraft $draft, ?callable $progress = null, ?int $telegramUpdateId = null, bool $sourcesAlreadyQueued = false): array
    {
        $telegramUpdateId ??= $draft->telegram_update_id;
        $resolved = [];
        $category = Category::query()
            ->where('slug', trim((string) $draft->category))
            ->first();
        $categoryStrategy = $category?->gallerySearchStrategy() ?? Category::GALLERY_SEARCH_AUTO;
        $minimumVerifiedImages = $category?->minimumVerifiedImages() ?? 3;
        $activeRecipeOnly = $categoryStrategy === Category::GALLERY_SEARCH_VISION_FIRST;
        $resolveLimit = (int) config('product-images.resolve_limit', 16);

        if (! $sourcesAlreadyQueued) {
            $sources = array_map(fn (array $source): array => [
                ...$source,
                '_preflight_active_recipe' => $this->recipeRouter->activeRecipeForUrl((string) ($source['url'] ?? '')) !== null,
                '_preflight_known_recipe_domain' => $this->recipeRouter->domainHasActiveRecipe((string) ($source['url'] ?? '')),
            ], $sources);
            $sources = $this->sourcePriority->reuseFirstQueue($sources, $activeRecipeOnly);
        }

        foreach (array_values($sources) as $sourceIndex => $source) {
            $pageUrl = is_string($source['url'] ?? null) ? $source['url'] : null;

            if (! $pageUrl) {
                continue;
            }
            $source['_minimum_verified_images'] = $minimumVerifiedImages;
            $source['_category_slug'] = trim((string) $draft->category) !== '' ? trim((string) $draft->category) : null;
            $source[ProductRequestContext::SOURCE_KEY] = $draft->id;
            $source['_minimum_image_width'] = $category?->minimumImageWidth();
            $source['_minimum_image_height'] = $category?->minimumImageHeight();

            // Photo eligibility is independent of exact technical configuration.
            // A different SKU/RAM needs an applicability judgment, not a blanket
            // rejection before the browser can inspect the observed gallery.
            $preflightEvidence = $this->preflightEvidence($source, $telegramUpdateId);
            $photoAssessment = $preflightEvidence !== null
                ? app(ProductSourceIdentityGate::class)->assessPhotos($draft, $preflightEvidence, $telegramUpdateId) : null;
            if ($photoAssessment !== null && ! $this->mayInspectPhotos($photoAssessment)) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $pageUrl,
                    'actor' => 'identity_validator',
                    'phase' => 'source_selection',
                    'action' => 'validate_discovery_page_identity',
                    'status' => $photoAssessment['verdict'] === 'conflicting' ? 'failed' : 'interrupted',
                    'decision' => $photoAssessment['verdict'] === 'conflicting'
                        ? 'reject_incompatible_photos_before_browser' : 'defer_unconfirmed_photos_before_browser',
                    'output' => ['photo_applicability' => $photoAssessment, 'source_evidence' => $preflightEvidence],
                ]);
                $progress?->__invoke('Фотоисточник отложен до запуска браузера: '.$photoAssessment['reason'].' · '.$pageUrl);

                continue;
            }
            if ($photoAssessment !== null) {
                $source['_photo_applicability'] = $photoAssessment;
                $source['_observed_identity'] = [
                    'purpose' => 'photos', 'verdict' => $photoAssessment['verdict'],
                    'product_draft_id' => $draft->id, 'telegram_update_id' => $telegramUpdateId,
                    'source' => $preflightEvidence, '_photo_applicability' => $photoAssessment,
                ];
            }

            $pageImages = $progress
                ? $this->resolver->resolve(
                    [$source],
                    $resolveLimit,
                    fn (string $level, string $message) => $progress($message),
                    $telegramUpdateId,
                    activeRecipeOnly: $activeRecipeOnly || (bool) ($source['_reuse_only'] ?? false),
                )
                : $this->resolver->resolve(
                    [$source], $resolveLimit, telegramUpdateId: $telegramUpdateId,
                    activeRecipeOnly: $activeRecipeOnly || (bool) ($source['_reuse_only'] ?? false),
                );

            if ($pageImages === []) {
                continue;
            }

            $runtimeContext = $this->resolver->sourceContextForPage($pageUrl) ?? collect($pageImages)
                ->map(fn (string $imageUrl): ?array => $this->resolver->sourceContextForImage($imageUrl))
                ->first(fn (mixed $context): bool => is_array($context));
            // Some stores use opaque/numeric product URLs and the browser
            // extractor may have no richer runtime metadata. Preserve the
            // Web Search title/SKU evidence instead of degrading that exact
            // card to a bare URL and rejecting it later as unconfirmed.
            $runtimeContext ??= [...$source, 'url' => $pageUrl];

            $finalPageUrl = is_string($runtimeContext['url'] ?? null)
                ? $runtimeContext['url']
                : $pageUrl;
            $runtimeContext = [...$runtimeContext, 'url' => $finalPageUrl];
            $photoAssessment = app(ProductSourceIdentityGate::class)->assessPhotos($draft, $runtimeContext, $telegramUpdateId);
            $identityVerdict = $photoAssessment['verdict'];
            $identityRejected = ! $this->mayInspectPhotos($photoAssessment);

            if ($identityRejected) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId,
                    'product_draft_id' => $draft->id,
                    'product_url' => $finalPageUrl,
                    'actor' => 'identity_validator',
                    'phase' => 'source_selection',
                    'action' => 'validate_discovery_runtime_identity',
                    'status' => 'failed',
                    'decision' => $identityVerdict === 'conflicting' ? 'reject_runtime_incompatible_photos' : 'defer_runtime_unconfirmed_photos',
                    'input' => ['requested_url' => $pageUrl],
                    'output' => ['final_source_context' => $runtimeContext, 'photo_applicability' => $photoAssessment],
                ]);
                $progress?->__invoke('Фотоисточник резервного поиска отложен после открытия: '.$photoAssessment['reason'].' · '.$finalPageUrl);

                continue;
            }
            $runtimeContext['_photo_applicability'] = $photoAssessment;
            $runtimeContext['_observed_identity'] = [
                'purpose' => 'photos', 'verdict' => $identityVerdict,
                'product_draft_id' => $draft->id, 'telegram_update_id' => $telegramUpdateId,
                'source' => array_intersect_key($runtimeContext, array_flip(['url', 'title', '_preflight_identity_evidence'])),
                '_photo_applicability' => $photoAssessment,
            ];

            foreach ($pageImages as $imageUrl) {
                $key = ImageUrls::normalizeCandidateUrl($imageUrl);
                $context = $this->resolver->sourceContextForImage($imageUrl) ?? $runtimeContext;
                if (($context['url'] ?? null) === $runtimeContext['url']) {
                    $context['_photo_applicability'] = $photoAssessment;
                    $context['_observed_identity'] = $runtimeContext['_observed_identity'];
                }
                $finalPageUrl = is_string($context['url'] ?? null) ? $context['url'] : $pageUrl;
                $this->sourcePagesByImageUrl[$key] = $finalPageUrl;
                $this->sourceContextsByImageUrl[$key] = $context;
            }

            $resolved = [...$resolved, ...$pageImages];
            if (count(array_filter(
                $pageImages,
                fn (string $url): bool => $this->resolver->isConfirmedGalleryImage($url),
            )) >= $minimumVerifiedImages) {
                // Structural confirmation is not final acceptance. Yield to
                // download/Vision now; retain unopened pages if those fail.
                $this->pendingSources = array_slice(array_values($sources), $sourceIndex + 1);
                $this->yieldedGallery = true;
                break;
            }
        }

        return array_values(array_unique($resolved));
    }

    /** Inspection permission is not acceptance of these images for publication. */
    private function mayInspectPhotos(array $assessment): bool
    {
        return in_array($assessment['verdict'] ?? null, ['exact', 'compatible'], true)
            || (($assessment['verdict'] ?? null) === 'uncertain' && ($assessment['may_inspect_gallery'] ?? false));
    }

    /**
     * Apply result limits per logical product page, then globally. This keeps
     * a confirmed gallery atomic and prevents one page with many renditions
     * from evicting every frame of the next independent exact card.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    /**
     * One cheap HTTP fetch of the page's own markup, for the model code it
     * prints about itself. Returns null when the page could not be read at all
     * - silence must not reject anything, only a contradiction may.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function preflightEvidence(array $source, ?int $telegramUpdateId): ?array
    {
        try {
            $preflight = $this->resolver->preflightSource($source, $telegramUpdateId);
        } catch (Throwable) {
            return null;
        }

        $evidence = trim((string) ($preflight['identity_evidence'] ?? ''));

        if ($evidence === '') {
            return null;
        }

        return [
            ...$source,
            'url' => (string) ($preflight['final_url'] ?? $source['url']),
            // The actual title is already part of identity_evidence. A stale
            // search title must not confirm a code absent from the real page.
            'title' => null,
            '_preflight_identity_evidence' => $evidence,
            '_preflight_specification_text' => trim((string) ($preflight['specification_text'] ?? '')),
            '_preflight_page_identity' => $preflight['page_identity'] ?? [],
        ];
    }

    private function limitCandidateUrlsBySource(array $urls): array
    {
        $globalLimit = max(1, (int) config('product-images.ai_result_limit', 20));
        $perSourceLimit = max(1, min(
            $globalLimit,
            (int) config('product-images.max_images', 10),
        ));

        $kept = collect($urls)
            ->values()
            ->map(function (string $url, int $index): array {
                $key = ImageUrls::normalizeCandidateUrl($url);
                $pageUrl = $this->sourceContextsByImageUrl[$key]['url']
                    ?? $this->sourcePagesByImageUrl[$key]
                    ?? null;

                return [
                    'url' => $url,
                    'index' => $index,
                    'group' => is_string($pageUrl) && filter_var($pageUrl, FILTER_VALIDATE_URL)
                        ? 'page:'.rtrim($pageUrl, '/')
                        : 'image:'.sha1($url),
                    'gallery_rank' => $this->resolver->isConfirmedGalleryImage($url)
                        ? 2
                        : ($this->resolver->isPartialGalleryImage($url) ? 1 : 0),
                ];
            })
            ->groupBy('group')
            ->map(fn ($group): array => [
                'first_index' => $group->min('index'),
                'gallery_rank' => $group->max('gallery_rank'),
                'items' => $group
                    ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                        ?: ($left['index'] <=> $right['index'])
                    )
                    ->take($group->max('gallery_rank') === 2 ? $group->count() : $perSourceLimit)
                    ->values(),
            ])
            ->sort(fn (array $left, array $right): int => ($right['gallery_rank'] <=> $left['gallery_rank'])
                ?: ($left['first_index'] <=> $right['first_index'])
            )
            ->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item, int $index): bool => $item['gallery_rank'] === 2 || $index < $globalLimit)
            ->pluck('url')
            ->values()
            ->all();

        $this->recordCappedSources($urls, $kept);

        return $kept;
    }

    /**
     * A page whose frames do not survive these caps disappears without a word:
     * the operator is told "recipe published, 12 photos" and then never hears
     * what became of them, and the database holds no answer either. Seen live
     * (2026-09-03, draft #88) - one shop's trained gallery was announced and
     * silently never downloaded, and there was nothing to read afterwards but
     * the absence of a row. The cap is legitimate; being unable to see it act
     * is not.
     *
     * @param  array<int, string>  $all
     * @param  array<int, string>  $kept
     */
    private function recordCappedSources(array $all, array $kept): void
    {
        $pageOf = function (string $url): ?string {
            $key = ImageUrls::normalizeCandidateUrl($url);
            $page = $this->sourceContextsByImageUrl[$key]['url'] ?? $this->sourcePagesByImageUrl[$key] ?? null;

            return is_string($page) && $page !== '' ? rtrim($page, '/') : null;
        };
        $survivors = collect($kept)->map($pageOf)->filter()->unique()->all();
        $dropped = collect($all)
            ->map($pageOf)
            ->filter()
            ->unique()
            ->reject(fn (string $page): bool => in_array($page, $survivors, true))
            ->values();

        foreach ($dropped as $page) {
            $this->attempts->record([
                'product_url' => $page,
                'actor' => 'downloader',
                'phase' => 'fallback_image_download',
                'action' => 'select_discovered_candidates',
                'status' => 'failed',
                'decision' => 'drop_source_over_candidate_limit',
                'output' => [
                    'kept_sources' => count($survivors),
                    'candidate_limit' => max(1, (int) config('product-images.ai_result_limit', 20)),
                ],
            ]);
        }
    }
}
