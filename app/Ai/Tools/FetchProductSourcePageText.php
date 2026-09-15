<?php

namespace App\Ai\Tools;

use App\Models\ProductSourcePageEvidence;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The "safe tool" ProductSpecificationReconciliationAgent gets to read more
 * of the chosen source when source_specification_text does not say enough -
 * e.g. a "characteristics"/"specifications" tab whose URL was not already
 * captured, or source_url itself when no page text was captured for it at
 * all. Scoped to one specific host at construction time (never a free-form
 * parameter the model could point anywhere) - the one thing "safe" means
 * here: this can only ever read more of the SAME site's own pages, never an
 * arbitrary URL, and a redirect or click that lands off that host is refused
 * rather than silently trusted (see ProductImageResolver::fetchAdditionalPageText()'s
 * own host check). Its underlying fetch can fall back to a real browser
 * session for Playwright-only sources, so it is metered against the same
 * shared search budget as the rest of that search rather than being an
 * unbounded side door.
 *
 * A successful read is persisted to ProductSourcePageEvidence for this
 * draft, the same table the reconciler's own initial payload already reads
 * from - so a later attempt (a fresh reconcile() call after this one was
 * interrupted, or a genuinely new one) starts from what was already found
 * instead of paying to rediscover it.
 */
class FetchProductSourcePageText implements Tool
{
    public function __construct(
        private readonly string $allowedHost,
        private readonly ?int $telegramUpdateId = null,
        private readonly ?int $productDraftId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Fetch and read a specific page on the SAME site as source_url - e.g. a "характеристики" or '
            .'"specifications" tab you noticed but whose text was not already provided - only when '
            .'source_specification_text does not say enough to confirm something the operator specifically '
            .'asked about. Only a URL on the exact same host as source_url is allowed; anything else is refused. '
            .'If the specifications are behind an in-page control rather than a different URL (a tab/accordion '
            .'you noticed on the page you already have text for), pass that same url with open_selector set to '
            .'it - this opens a real browser, clicks it, and reads what it reveals; plain fetching cannot click '
            .'anything. Set force_browser when a plain read already returned some text but you judge it too '
            .'thin to be the real content (e.g. only a title, the rest rendered by JavaScript) - otherwise a '
            .'non-empty plain-text result is trusted as-is and the browser is not tried. '
            .'An empty result means the page could not be read, not that it contradicts anything - try a '
            .'different URL/selector or return not_ready, never invent a value to fill the gap. '
            .'The response also carries identity_evidence (what this specific page says it is - compare it '
            .'with source_identity_snippet before treating specification_text as evidence for the SAME product, '
            .'since being on the right site does not mean this is the right page), observed_controls (real, '
            .'clickable selectors this page actually has - when a real browser ran - to pick your NEXT '
            .'open_selector from verbatim rather than inventing one), and click_outcome (whether the selector '
            .'you asked for actually existed and was clicked, or the click left this product entirely).';
    }

    public function handle(Request $request): Stringable|string
    {
        $url = trim((string) $request->string('url'));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $openSelector = trim((string) $request->string('open_selector'));
        $forceBrowser = $request->boolean('force_browser');

        if ($url === '' || $host === '' || $host !== strtolower($this->allowedHost)) {
            return json_encode([
                'fetched' => false,
                'reason' => 'url must be on the same host as source_url',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // The underlying fetch can launch a real browser session (Playwright
        // fallback, or opening a selector) - not free, and not something a
        // per-call tool budget would bound on its own. Same shared search
        // budget everything else in this pipeline already answers to,
        // checked directly rather than via a heuristic (no separate money
        // budget for this step).
        if ($this->telegramUpdateId !== null && (
            app(ProductSearchCostBudget::class)->exceeded($this->telegramUpdateId)
            || ! app(ProductSearchTimeBudget::class)->canStart($this->telegramUpdateId, 20)
        )) {
            return json_encode([
                'fetched' => false,
                'reason' => 'search budget exhausted',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $selector = $openSelector !== '' ? $openSelector : null;
        $result = app(ProductImageResolver::class)->fetchAdditionalPageText(
            $url,
            $this->telegramUpdateId,
            $selector,
            $forceBrowser,
        );

        if ($result['blocked_reason'] !== null) {
            // A redirect or an in-page click landed off the host this call
            // was scoped to - whatever that page says, it is not this one's
            // content, and must not be handed over as if it were.
            return json_encode([
                'fetched' => false,
                'reason' => 'page left the allowed host ('.$result['final_url'].')',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($result['text'] !== '' && $this->productDraftId !== null) {
            // Keying purely on the url would let a second state of the same
            // page (a different tab opened on the same address) silently
            // overwrite the first - the two are different observations of
            // the same URL, not the same one written twice. The selector is
            // folded into the key precisely when one was used; a plain read
            // (no selector) keeps the same key the reconciler's own
            // capture-at-search-time baseline already uses for this url, so
            // a tool refetch of the default state still updates that one
            // row rather than creating an unrelated duplicate.
            $keySuffix = $selector !== null ? '#'.$selector : '';
            ProductSourcePageEvidence::query()->updateOrCreate(
                [
                    'product_draft_id' => $this->productDraftId,
                    'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl($url).$keySuffix),
                ],
                [
                    'url' => $url,
                    'final_url' => $result['final_url'],
                    'specification_text' => $result['text'],
                    'identity_evidence' => $result['identity_evidence'],
                    'captured_via' => $result['via'],
                    'open_selector' => $selector,
                    'click_outcome' => $result['click_outcome'],
                ],
            );
        }

        return json_encode([
            'fetched' => $result['text'] !== '',
            'specification_text' => mb_substr($result['text'], 0, 8_000),
            'identity_evidence' => mb_substr($result['identity_evidence'], 0, 2000),
            // Only present when a real browser actually ran (plain HTTP has
            // no notion of clickable controls) - pick one of these verbatim
            // for a future open_selector rather than inventing a selector
            // from the text above.
            'observed_controls' => $result['observed_controls'],
            'click_outcome' => $result['click_outcome'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->max(2048)->required()->description(
                'A specific page URL on the same site as source_url, e.g. a specifications/characteristics tab.',
            ),
            'open_selector' => $schema->string()->max(300)->nullable()->required()->description(
                'A CSS selector for an element on THIS url (a tab/accordion/button) to click before reading, '
                    .'when the content you need is revealed in-page rather than at a different URL. '
                    .'Null/omit to just read the page as given.',
            ),
            'force_browser' => $schema->boolean()->required()->description(
                'True to use a real browser even if a plain read of this url would return some non-empty text - '
                    .'set this when you judge that text insufficient (only a title, content you know is '
                    .'JavaScript-rendered). False trusts a non-empty plain-text result as-is.',
            ),
        ];
    }
}
