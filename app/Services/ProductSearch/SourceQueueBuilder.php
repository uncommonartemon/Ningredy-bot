<?php

namespace App\Services\ProductSearch;

use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Services\Photos\CandidateUrlOrdering;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Support\Collection;

/** Builds and journals the source queue; never trains recipes or publishes a draft. */
class SourceQueueBuilder
{
    public function __construct(
        private readonly ProductSourcePriority $sourcePriority,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
        private readonly ProductImageCandidateDiscovery $candidateDiscovery,
        private readonly ProductImageResolver $resolver,
        private readonly ProductIdentityMatcher $identityMatcher,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly CandidateUrlOrdering $candidateUrls,
    ) {}

    /**
     * Which pages this search will open, and in what order.
     *
     * Everything from "here are the addresses research returned" to "here
     * is the queue": ranking by past reliability, hoisting the draft's own
     * primary source, dropping documents and pages this draft has already
     * failed on, the two free filters (a colour the listing itself
     * contradicts, and the shops we hold a recipe for), the per-host cap,
     * the preflight budget, and finally the reuse-first ordering.
     *
     * Cut out of stage() as the first of several: at 1,273 lines that
     * method is where a tab-name list could hide for a month, and this
     * part of it answers one self-contained question with no state shared
     * with the loop that follows. Behaviour is unchanged - the body is the
     * same statements in the same order, and the suite is what says so.
     *
     * @param  array<int, array<string, mixed>>  $cycleSources
     * @param  array<int, string>  $cycleExcludedSourceUrls
     * @param  null|callable(string): void  $progress
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    public function buildSourceQueue(
        ProductDraft $draft,
        array $cycleSources,
        array $cycleExcludedSourceUrls,
        bool $activeRecipeOnly,
        int $minimumCompleteGallerySize,
        ?int $telegramUpdateId,
        ?callable $progress,
    ): array {
        $rawSources = [
            ...$cycleSources,
            ...($draft->sources ?? []),
        ];
        $seenSourceUrls = [];
        foreach ($rawSources as $source) {
            $url = is_array($source) && is_string($source['url'] ?? null) ? $source['url'] : null;
            $key = $url !== null ? rtrim($url, '/') : '';
            $reason = match (true) {
                $url === null => 'invalid_source',
                $this->sourcePriority->isBlockedUrl($url) => 'blocked_domain',
                isset($seenSourceUrls[$key]) => 'duplicate_url',
                default => null,
            };
            if ($reason !== null) {
                $this->attempts->record([
                    'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
                    'product_url' => $url ?? '', 'actor' => 'server', 'phase' => 'source_queue',
                    'action' => 'filter_candidate', 'status' => 'skipped', 'decision' => $reason,
                ]);
            } else {
                $seenSourceUrls[$key] = true;
            }
        }
        $prioritizedSources = collect($this->sourcePriority->sortSources($rawSources, $draft->brand))
            ->unique(fn (array $source): string => rtrim((string) ($source['url'] ?? ''), '/'))
            ->values()
            ->all();
        // Historical reliability decides which source is selected before the
        // cycle starts, but a failed HTML/Playwright probe during that same
        // cycle must not immediately demote the chosen primary source before
        // its already-known direct image URLs are downloaded and checked by
        // Vision. Keep it first for this draft; only a real end-to-end gallery
        // failure is allowed to advance to the remaining ranked sources.
        if (is_string($draft->primary_source_url) && $draft->primary_source_url !== '') {
            $primarySources = array_values(array_filter(
                $prioritizedSources,
                fn (array $source): bool => ($source['url'] ?? null) === $draft->primary_source_url,
            ));
            $otherSources = array_values(array_filter(
                $prioritizedSources,
                fn (array $source): bool => ($source['url'] ?? null) !== $draft->primary_source_url,
            ));
            $prioritizedSources = [...$primarySources, ...$otherSources];
        }
        $cardSources = collect($prioritizedSources)
            ->filter(function (mixed $source) use ($draft, $cycleExcludedSourceUrls, $telegramUpdateId): bool {
                $url = is_array($source) && is_string($source['url'] ?? null) ? $source['url'] : '';
                $reason = match (true) {
                    $url === '' => 'invalid_source',
                    // web means an unclassified source, not proof that its
                    // HTML is not a product card. Let page evidence decide.
                    ! in_array($source['type'] ?? null, ['retailer', 'marketplace', 'manufacturer', 'web'], true) => 'not_product_card_type',
                    // The same document test the fallback search has always applied.
                    // Research produces this list, and nothing filtered it: a search
                    // opened with a PDF catalogue as source #1 twice in one day
                    // (acerid.com's brochure, gzhls.at's datasheet), each time
                    // spending a fetch to be told it was not HTML.
                    ! $this->candidateDiscovery->looksLikeHtmlProductPage($url) => 'non_html_document',
                    $this->recipeRouter->domainIsBlocked($url) => 'blocked_by_recipe_router',
                    $this->sourceExcludedForDraft($url, $draft) => 'excluded_for_draft',
                    $this->sourceExcludedByUrls($url, $cycleExcludedSourceUrls) => 'already_finished_in_cycle',
                    default => null,
                };
                if ($reason !== null) {
                    $this->attempts->record([
                        'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
                        'product_url' => $url, 'actor' => 'server', 'phase' => 'source_queue',
                        'action' => 'filter_candidate', 'status' => 'skipped', 'decision' => $reason,
                        'output' => ['source_type' => $source['type'] ?? null],
                    ]);
                }

                return $reason === null;
            })
            ->values();

        // A colour filter stood here and it was a word list. It dropped a Grey
        // listing for a Gray request, a "Золотой" one for a Gold request, and
        // any card whose title said "Black Friday" - each because a vocabulary
        // of colour words cannot survive spelling, language, or a marketing
        // phrase. It was written the day after a list of English tab names was
        // removed for being unfinishable, and it was the same mistake facing
        // the other way. Colour is judged by Vision on the frames, where the
        // evidence is, and what to do about it is the agent's call.
        //
        // The shops we already know how to open, moved to the front. This
        // was decided inside preflight, which means it was decided after we had
        // already spent a request on every candidate - while it follows from
        // the host alone, and the recipes are per-domain now. Five recipes have
        // been trained and not one has ever been reused; a search reaches them
        // only by accident because nothing puts them first while it still costs
        // nothing to do so.
        $cardSources = $this->knownShopsFirst($cardSources);
        $cardSources = $this->limitPerHost($cardSources, $progress);

        if (config('product-images.source_preflight', true)) {
            $progress?->__invoke('Быстро проверяю доступность карточек, CAPTCHA/WAF, статические фото и готовые рецепты до запуска Playwright.');
            // Research may return dozens of candidates and each preflight is a
            // real request. The ones past this budget keep their place in the
            // queue and are opened only if the ones before them come to
            // nothing - unchecked, not discarded.
            $preflightBudget = max(1, (int) config('product-images.max_preflight_sources', 4));
            $unchecked = $cardSources->slice($preflightBudget)->values();
            $cardSources = $cardSources->take($preflightBudget)
                ->map(function (array $source, int $index) use ($telegramUpdateId, $draft): array {
                    $attemptCheckpoint = ProductSourceAttempt::query()->max('id') ?? 0;

                    try {
                        $preflight = $this->resolver->preflightSource($source, $telegramUpdateId);
                    } finally {
                        $this->attempts->associateWithDraftSince($draft, $telegramUpdateId, $attemptCheckpoint);
                    }

                    return [
                        ...$source,
                        'title' => trim((string) ($preflight['identity_evidence'] ?? '')) !== ''
                            ? null : ($source['title'] ?? null),
                        'image_urls' => array_values(array_unique([
                            ...$this->candidateUrls->cleanUrls($preflight['static_image_urls'] ?? []),
                            ...$this->candidateUrls->cleanUrls($source['image_urls'] ?? []),
                        ])),
                        '_preflight_blocked' => (bool) ($preflight['blocked'] ?? false),
                        '_preflight_unavailable' => (bool) ($preflight['unavailable'] ?? false),
                        '_preflight_active_recipe' => (bool) ($preflight['active_recipe'] ?? false),
                        '_preflight_known_recipe_domain' => (bool) ($preflight['known_recipe_domain'] ?? false),
                        // Set on a 401/403/429 or a detected JS/cookie gate:
                        // the cheap HTTP fetch was refused, but a real
                        // headless browser often gets through where it
                        // can't - was computed and then never read by
                        // anything downstream, so a bot-blocked official
                        // source (e.g. a manufacturer's own store) silently
                        // sank to the bottom on "zero evidence" exactly like
                        // a genuinely irrelevant one, with nothing left to
                        // tell them apart once ranked below a worse source
                        // that merely fetched without erroring.
                        '_preflight_browser_probe_required' => (bool) ($preflight['browser_probe_required'] ?? false),
                        '_preflight_final_url' => (string) ($preflight['final_url'] ?? $source['url']),
                        '_preflight_identity_evidence' => (string) ($preflight['identity_evidence'] ?? ''),
                        '_preflight_specification_text' => (string) ($preflight['specification_text'] ?? ''),
                        '_preflight_is_primary' => rtrim((string) ($source['url'] ?? ''), '/')
                            === rtrim((string) ($draft->primary_source_url ?? ''), '/'),
                        '_preflight_index' => $index,
                    ];
                })
                ->filter(fn (array $source): bool => ! $source['_preflight_blocked'] && ! $source['_preflight_unavailable'])
                ->sortByDesc(fn (array $source): array => [
                    $this->identityMatcher->supportsSource($draft, $source) ? 1 : 0,
                    $source['_preflight_active_recipe'] ? 1 : 0,
                    $source['_preflight_known_recipe_domain'] ? 1 : 0,
                    $source['_preflight_is_primary'] ? 1 : 0,
                    $source['_preflight_browser_probe_required'] ? 1 : 0,
                    count($source['image_urls'] ?? []) >= $minimumCompleteGallerySize ? 1 : 0,
                    -$source['_preflight_index'],
                ])
                ->values();
            // Behind the checked ones, in the order research gave them. A
            // source nobody looked at is not a source that failed.
            $cardSources = $cardSources->concat($unchecked)->values();
        }

        // Trying another already-known candidate is core search behaviour.
        // The caller owns execution and the shared search budget.
        // fallbackSourcesEnabled() controls a genuinely separate, costlier
        // mechanism further down: the broad AI web search for sources this
        // draft's own research never found at all (discoverCandidates()).

        // Sources whose domain already has a usable recipe are tried before any
        // training happens - see ProductSourcePriority::reuseFirstQueue() for
        // why, and for which of them are worth queueing twice.
        $sourceQueue = collect($this->sourcePriority->reuseFirstQueue($cardSources->all(), $activeRecipeOnly));
        $reuseFirstCount = $sourceQueue->count() - $cardSources->count();
        $queueSummary = 'Очередь: '.$cardSources->count().' карточек; шагов обработки: '.$sourceQueue->count()
            .' (включая отдельные пробы готовых рецептов: '.$reuseFirstCount.').';
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId, 'product_draft_id' => $draft->id,
            'product_url' => $draft->primary_source_url ?? '', 'actor' => 'server',
            'phase' => 'source_queue', 'action' => 'queue_summary', 'status' => 'completed',
            'message' => $queueSummary,
            'output' => ['card_count' => $cardSources->count(), 'queue_count' => $sourceQueue->count(), 'reuse_probe_count' => $reuseFirstCount],
        ]);
        $progress?->__invoke($queueSummary);

        if ($reuseFirstCount > 0) {
            $progress?->__invoke(
                'Ниже по списку есть карточки с готовыми рецептами ('.$reuseFirstCount
                    .'): пробую их до обучения новых.',
            );
        }

        foreach ($sourceQueue as $index => $source) {
            $this->attempts->record([
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draft->id,
                'product_url' => $source['url'],
                'actor' => 'server',
                'phase' => 'source_queue',
                'action' => 'rank_candidate',
                'status' => 'completed',
                'decision' => 'queued',
                'output' => [
                    'position' => $index + 1,
                    'recipe_priority' => $source['_queue_reason'] ?? 'unfamiliar_domain',
                    'preflight_checked' => array_key_exists('_preflight_index', $source),
                    'reuse_only' => (bool) ($source['_reuse_only'] ?? false),
                ],
            ]);
        }

        // The queue and the cards it was built from: one source below needs
        // the addresses of every candidate, not the order they are opened in.
        return [$sourceQueue, $cardSources];
    }

    /**
     * Shops we already know how to open, moved to the front.
     *
     * Derived from the host and the recipe library - one query, no request to
     * anybody. It used to be decided inside preflight, which is to say after a
     * request had already been spent on every candidate, and by then the order
     * could no longer save any.
     *
     * Nothing is dropped: a shop with a recipe is a cheaper first try, not a
     * better product, and the identity checks downstream are unchanged.
     *
     * @param  Collection<int, array<string, mixed>>  $sources
     * @return Collection<int, array<string, mixed>>
     */
    public function knownShopsFirst(Collection $sources): Collection
    {
        $known = [];
        $sources = $sources->map(function (array $source) use (&$known): array {
            $url = (string) ($source['url'] ?? '');
            $host = $this->recipeRouter->domainForUrl($url);
            $active = $host !== '' && $this->recipeRouter->activeRecipeForUrl($url) !== null;
            $domainActive = $host !== '' && ($known[$host] ??= $this->recipeRouter->domainHasActiveRecipe($url));

            return [
                ...$source,
                '_preflight_active_recipe' => $active,
                '_preflight_known_recipe_domain' => $domainActive,
                '_queue_reason' => $active ? 'matching_active_recipe' : ($domainActive ? 'known_recipe_domain' : 'unfamiliar_domain'),
            ];
        });
        // Stable partitions preserve reliability and discovery order on ties.
        [$exact, $others] = $sources->partition(fn (array $source): bool => $source['_preflight_active_recipe']);
        [$compatible, $unknown] = $others->partition(fn (array $source): bool => $source['_preflight_known_recipe_domain']);

        return $exact->concat($compatible)->concat($unknown)->values();
    }

    /**
     * Diversify the first pass without deleting any candidate page.
     *
     * The fallback search has had this since the day research returned four
     * acer.com links and the bot visited all four inside three minutes - they
     * were never four chances, they were one shop asked four times, and they
     * failed together while looking exactly like a scraper. The main queue,
     * which is where researched cards actually go, never got the same rule.
     *
     * It matters more now that breadth is asked for: fifty candidates from a
     * widely-sold product can easily be six pages of the same three retailers.
     * Extra pages remain at the end: another path can have a working layout or
     * the exact variant even when the first two pages did not.
     *
     * @param  Collection<int, array<string, mixed>>  $sources
     * @return Collection<int, array<string, mixed>>
     */
    public function limitPerHost(Collection $sources, ?callable $progress): Collection
    {
        $cap = max(1, (int) config('product-images.max_sources_per_host', 2));
        $seen = [];
        $dropped = 0;

        [$kept, $deferred] = $sources->partition(function (array $source) use ($cap, &$seen, &$dropped): bool {
            $host = $this->recipeRouter->domainForUrl((string) ($source['url'] ?? ''));

            if ($host === '') {
                return true;
            }

            $seen[$host] = ($seen[$host] ?? 0) + 1;
            $dropped += $seen[$host] > $cap ? 1 : 0;

            return $seen[$host] <= $cap;
        });

        if ($dropped > 0) {
            $progress?->__invoke(sprintf(
                'Перенёс %d дополнительных страниц магазинов в конец очереди: сначала до %d с домена, остальные сохранены.',
                $dropped,
                $cap,
            ));
        }

        return $kept->concat($deferred)->values();
    }

    public function sourceExcludedForDraft(string $url, ProductDraft $draft): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($draft->excluded_gallery_source_urls ?? [])
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
    }

    /** @param array<int, string> $excludedUrls */
    private function sourceExcludedByUrls(string $url, array $excludedUrls): bool
    {
        $host = ProductSourcePriority::host($url);

        if ($host === '') {
            return false;
        }

        return collect($excludedUrls)
            ->filter(fn (mixed $excluded): bool => is_string($excluded))
            ->contains(fn (string $excluded): bool => ProductSourcePriority::hostsMatch(
                $host,
                ProductSourcePriority::host($excluded),
            ));
    }
}
