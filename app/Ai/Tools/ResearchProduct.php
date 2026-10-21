<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Products\ProductCatalogIdentity;
use App\Services\Products\ProductCategoryResolver;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductPublicDescription;
use App\Services\Products\ProductResearchConfigurationGuard;
use App\Services\Products\ProductResearchResponseNormalizer;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Products\ProductSourcePriority;
use App\Services\Products\ProductTextEvidenceContract;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use App\Services\ProductSearch\Research\InvalidResearchResponse;
use App\Services\ProductSearch\Research\RecoverableProductResearch;
use App\Services\ProductSearch\Research\ResearchSourceRoles;
use App\Services\ProductSearch\Research\ResearchWebSearchTrace;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class ResearchProduct implements Tool
{
    use RecordsOperations;

    public function __construct(
        private readonly TelegramUpdate $update,
        private readonly ProductImageResolver $imageResolver,
        private readonly ?ProductSourcePriority $sourcePriority = null,
        private readonly ?ProductPublicDescription $publicDescription = null,
        private readonly ?ProductImageStorage $imageStorage = null,
        private readonly ?ProductResearchResponseNormalizer $responseNormalizer = null,
        private readonly ?ProductCardPresenter $productCards = null,
        private readonly ?StreamingProductResearch $streamingResearch = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Search the internet for a real product, verify its data and create a pending catalog draft. Use for explicit web searches or after local catalog search returns no suitable match.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->isCancelled()) {
            return $this->cancelledResult();
        }
        $query = trim((string) $request->string('query'));
        $payload = is_array($this->update->payload) ? $this->update->payload : [];
        $existingProgressMessageId = (int) ($payload['_progress_message_id'] ?? 0);
        $progress = new TelegramProgressReporter(
            app(TelegramClient::class),
            (string) $this->update->chat_id,
            existingMessageId: $existingProgressMessageId > 0 ? $existingProgressMessageId : null,
            onMessageCreated: function (int $messageId): void {
                $update = $this->update->fresh();

                if (! $update) {
                    return;
                }

                $payload = is_array($update->payload) ? $update->payload : [];
                $payload['_progress_message_id'] = $messageId;
                $update->update(['payload' => $payload]);
                $this->update->setAttribute('payload', $payload);
            },
        );
        $settings = app(AiSettings::class);
        $timeBudget = app(ProductSearchTimeBudget::class);

        // Never buy web research and a gallery when the mandatory final
        // assembly is administratively paused. Catalog reads remain available.
        if (! $settings->specificationReconciliationEnabled()) {
            $message = 'Поиск товара не запущен: сборка готовой карточки выключена в настройках AI. '
                .'Включите «Сборка карточки перед подтверждением». Интернет-поиск и обучение не запускались.';
            app(ProductSourceAttemptRecorder::class)->record([
                'telegram_update_id' => $this->update->id,
                'actor' => 'server', 'phase' => 'search_preflight', 'action' => 'check_card_assembly',
                'status' => 'interrupted', 'decision' => 'configuration_disabled', 'message' => $message,
            ]);

            return $this->json([
                'ok' => false, 'status' => 'configuration_blocked', 'retryable' => false,
                'reason' => 'card_assembly_disabled', 'message' => $message,
            ]);
        }

        if (! $timeBudget->canStart($this->update->id, 60)) {
            $progress->warning('Общий лимит поиска исчерпан; новый долгий этап не запускаю, чтобы корректно завершить запрос.');

            return $this->json([
                'ok' => false,
                'status' => 'time_budget_exhausted',
                'message' => 'Поиск достиг общего лимита времени. Запрос завершён без аварийного падения; его можно повторить отдельно.',
            ]);
        }

        $researchTimeout = $timeBudget->timeoutFor(
            $this->update->id,
            $settings->productResearchTimeoutSeconds(),
        );
        $researchIdleTimeout = min($researchTimeout, $settings->productResearchIdleTimeoutSeconds());
        $progress->withCancelButton($this->update->id);
        $progress->step('1/3 · поиск точной карточки и характеристик', $researchTimeout, $query);
        $provider = $settings->providerFor('product_research');
        $model = $settings->modelFor('product_research');
        $sourcePriority = $this->sourcePriority ?? app(ProductSourcePriority::class);
        $blockedDomains = $sourcePriority->blockedDomains();
        // The agent was told what to avoid and nothing about what already
        // works, so every search went shopping somewhere new and paid to learn
        // that shop from scratch: 111 recipes trained across this catalog, and
        // exactly two of them ever used a second time. These are the shops the
        // pipeline can already open. It is a preference, not a restriction -
        // the exact product still outranks a familiar address, and a search
        // that only ever revisited the same shops would never grow the list.
        $provenDomains = $sourcePriority->provenDomains();
        $inputContext = ProductInputContext::forUpdate($this->update);
        // Barcodes in the request are looked up in structured databases first.
        // A hint the agent may use or reject, never a verdict; any failure here
        // only means the ordinary web search runs as it always did.
        $codeLookup = app(ProductCodeLookup::class);
        $queryGtins = ProductCodeLookup::gtins($query);
        $codeHints = collect($queryGtins === [] ? [] : $codeLookup->lookup($queryGtins, null, [], $this->update->id, 'product_research'))
            ->filter(fn (array $result): bool => $result['found'])
            ->map(fn (array $result): array => array_intersect_key($result, array_flip(['source', 'code', 'title', 'brand', 'part_number', 'gtins', 'page_url', 'color'])))
            ->values()->all();
        // Research named one thing under two keys (display_size beside screen_size)
        // because it never saw which keys the catalog already has.
        $vocabulary = AttributeDefinition::query()->has('values')->withCount('values')
            ->orderByDesc('values_count')->limit(80)->get(['id', 'key', 'label', 'translations'])
            ->map(fn (AttributeDefinition $definition): string => $definition->key.' ('.($definition->translations['en'] ?? $definition->label).')')
            ->implode(', ');
        $researchPrompt = $query
            .PHP_EOL.'Input provenance and prioritized search alternatives (observations are not additional mandatory requirements): '
            .json_encode($inputContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            .($blockedDomains === [] ? '' : PHP_EOL.'Never search or return these blocked domains: '.implode(', ', $blockedDomains))
            .($provenDomains === [] ? '' : PHP_EOL.'This catalog already knows how to read product pages on these shops: '
                .implode(', ', $provenDomains)
                .'. When one of them genuinely sells the exact requested product, include its product page among the sources.'
                .' Never return a page from them for a different model, and never let this list narrow the search:'
                .' an exact match anywhere else is always worth more than a familiar domain.')
            .($codeHints === [] ? '' : PHP_EOL.'Structured product database results for codes in the request (hints, not verification; '
                .'track record: '.json_encode($codeLookup->trackRecord()).'): '
                .json_encode($codeHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                .'. Use them only if they match what the operator asked for; otherwise ignore them and search as usual.'
                .' Report your decision in code_lookup_verdict.')
            .($vocabulary === '' ? '' : PHP_EOL.'Specification keys this catalog already uses: '.$vocabulary
                .'. Give a specification one of these keys when it means the same thing; a new snake_case key only'
                .' for a concept not on the list. Two keys for one thing split the storefront filter in two.');
        $run = AiRun::query()->create([
            'telegram_update_id' => $this->update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $researchPrompt,
            'activity' => ['stage' => 'research'],
            'started_at' => now(),
        ]);

        try {
            // The stream trace is written over the whole column; keep the stage label in it.
            $activity = ['stage' => 'research'];
            $lastActivityWrite = 0.0;
            $cancelled = false;
            $reportedActivity = [];
            $webSearchItems = [];
            $recordActivity = function (StreamEvent $event) use (
                &$run,
                $progress,
                &$activity,
                &$lastActivityWrite,
                &$cancelled,
                &$reportedActivity,
                &$webSearchItems,
            ): void {
                $now = microtime(true);
                $stage = $event instanceof ProviderToolEvent
                    ? $event->type.'.'.$event->status
                    : $event->type();
                $traceable = $event instanceof StreamStart
                    || $event instanceof ProviderToolEvent
                    || $event instanceof TextStart
                    || $event instanceof StreamEnd;

                if ($event instanceof ProviderToolEvent && $event->type === 'web_search_call') {
                    $activity['provider_web_search_items'] = ResearchWebSearchTrace::record(
                        $activity['provider_web_search_items'] ?? [], $event,
                    );
                }

                if ($traceable && count(array_filter(array_keys($activity), 'is_int')) < 50) {
                    $activity[] = [
                        'at' => now()->toIso8601String(),
                        'stage' => $stage,
                    ];
                }

                if ($traceable || $now - $lastActivityWrite >= 5) {
                    $run->update([
                        'activity' => $activity,
                        'last_activity_at' => now(),
                    ]);
                    $lastActivityWrite = $now;
                    $cancelled = $this->isCancelled();
                }

                if ($event instanceof ProviderToolEvent && $event->type === 'web_search_call' && $event->itemId !== '') {
                    $webSearchItems[$event->itemId] = true;
                }
                if ($cancelled) {
                    return;
                }

                if ($event instanceof StreamStart && ! isset($reportedActivity['connected'])) {
                    $reportedActivity['connected'] = true;
                    $progress->info('OpenAI ответил; поток Web Search подключён.');
                }

                if ($event instanceof ProviderToolEvent && $event->type === 'web_search_call') {
                    if ($event->itemId !== '') {
                        $webSearchItems[$event->itemId] = true;
                    }

                    $key = 'web_search.'.$event->status;

                    if (isset($reportedActivity[$key])) {
                        return;
                    }

                    $reportedActivity[$key] = true;
                    $message = match ($event->status) {
                        'in_progress' => 'Web Search запущен.',
                        'searching' => 'Web Search ищет точные страницы товара.',
                        'completed' => 'Web Search завершён; анализирую результаты.',
                        default => null,
                    };

                    if ($message !== null) {
                        $progress->info($message);
                    }
                }
            };

            try {
                $research = app(RecoverableProductResearch::class)->run(
                    $run,
                    $this->streamingResearch ?? app(StreamingProductResearch::class),
                    $researchPrompt,
                    $provider,
                    $model,
                    $researchTimeout,
                    $researchIdleTimeout,
                    fn (array $data): array => $this->validateCorrectedResearchResponse($data),
                    $recordActivity,
                    fn (int $seconds) => $this->isCancelled() ? null : $progress->info(
                        'Другой тяжёлый AI-этап ещё выполняется; этот поиск продолжится примерно через '.$seconds.' сек.',
                    ),
                    function () use ($progress, &$webSearchItems, &$activity): void {
                        $progress->info('Ответ поиска пришёл неполным или не прошёл проверку; агент восстанавливает его по сохранённым данным.');
                        $webSearchItems = [];
                        $activity = ['stage' => 'research'];
                    },
                );
                $response = $research['response'];
            } finally {
                // /reset already acknowledged cancellation; do not resurrect
                // its obsolete progress message after the provider returns.
                if ($this->update->fresh()?->status !== 'cancelled') {
                    $progress->clearCancelButton();
                }
            }

            // Cancellation is still checked after the provider returns; the
            // streamed transport now limits silent waits, but it cannot
            // interrupt local post-processing already in progress.
            if ($this->isCancelled()) {
                return $this->cancelledResult($run, $response, count($webSearchItems));
            }

            $progress->info('Результат поиска получен; проверяю совпадение и источники.');
            $data = $research['data'];

            if ($this->isCancelled()) {
                return $this->cancelledResult($run, $response, count($webSearchItems));
            }

            $publicDescription = $this->publicDescription ?? app(ProductPublicDescription::class);
            $normalizedDescription = $publicDescription->normalize($data);
            $data['description'] = $normalizedDescription['description'];
            $data['research_notes'] = $normalizedDescription['research_notes'];
            $reportedSources = $data['sources'];
            $data['sources'] = $sourcePriority->sortSources($reportedSources, $data['brand'] ?? null);
            // Text evidence has its own ledger: a PDF or a second observation
            // of the same field must not disappear with gallery dedup/ranking.
            // A page kept only as a compatible photo candidate sells another
            // configuration: its quotations are not facts of the selected one.
            $photoOnly = collect($reportedSources)->reject(fn (array $source): bool => ResearchSourceRoles::maySupplyFacts($source))
                ->map(fn (array $source): string => rtrim((string) $source['url'], '/'))->all();
            $data['specification_evidence'] = array_values(array_filter($data['specification_evidence'] ?? [],
                fn (array $fact): bool => ! $sourcePriority->isBlockedUrl($fact['source_url'])
                    && ! in_array(rtrim((string) $fact['source_url'], '/'), $photoOnly, true)));
            if (is_array($data['code_lookup_verdict'] ?? null)) {
                $codeLookup->recordVerdict($this->update->id, (string) ($data['code_lookup_verdict']['decision'] ?? ''),
                    (string) ($data['code_lookup_verdict']['reason'] ?? ''), 'product_research');
            }
            $officialGallery = $data['status'] === 'found' ? $this->officialGallery($codeLookup, $data, $queryGtins) : null;
            if ($officialGallery !== null) {
                // The agent may list the same datasheet page itself (it saw it in the hint) as a
                // facts-only database entry; that entry must not shadow the gallery (live #439).
                $officialUrl = ProductImageStorage::normalizeCandidateUrl($officialGallery['source']['url']);
                $data['sources'] = [...array_values(array_filter($data['sources'], fn (array $source): bool => ProductImageStorage::normalizeCandidateUrl((string) ($source['url'] ?? '')) !== $officialUrl)),
                    $officialGallery['source']];
            }
            $attemptRecorder = app(ProductSourceAttemptRecorder::class);
            $blockedSourceCount = 0;
            foreach ($reportedSources as $source) {
                $blocked = $sourcePriority->isBlockedUrl((string) $source['url']);
                $blockedSourceCount += (int) $blocked;
                $attemptRecorder->record([
                    'telegram_update_id' => $this->update->id,
                    'product_url' => $source['url'],
                    'actor' => 'web_search',
                    'phase' => 'product_research',
                    'action' => 'candidate_source',
                    'status' => $blocked ? 'blocked' : 'completed',
                    'decision' => $blocked ? 'reject_global_block' : 'accept_candidate',
                    'output' => $source,
                ]);
            }
            $researchSummary = 'Запрос #'.$this->update->id.': исследование — '.$data['status']
                .'; найдено адресов: '.count($reportedSources).', исключено по бану домена: '.$blockedSourceCount
                .', передано дальше: '.count($data['sources']).'. Это кандидаты, ещё не проверенные галереи.';
            $attemptRecorder->record([
                'telegram_update_id' => $this->update->id,
                'product_url' => $data['primary_source_url'] ?? '',
                'actor' => 'server', 'phase' => 'product_research', 'action' => 'research_summary',
                'status' => 'completed', 'decision' => $data['status'], 'message' => $researchSummary,
                'output' => [
                    'reported_count' => count($reportedSources), 'domain_blocked_count' => $blockedSourceCount,
                    'remaining_count' => count($data['sources']),
                    'query' => $query, 'ai_run_id' => $run->id,
                    'web_search_calls' => count($webSearchItems),
                    'usage' => $response->usage->toArray(),
                    'research_notes' => $data['research_notes'] ?? null,
                    'selected_product' => array_intersect_key($data, array_flip(['title', 'brand', 'model', 'color', 'specifications'])),
                    'sources' => $data['sources'],
                ],
            ]);
            $progress->info($researchSummary);
            // Warn before photos and assembly are bought, then keep searching
            // for the closest real product instead of stopping (live #429-#431).
            $unconfirmedCodes = array_values(array_filter((array) ($data['unconfirmed_operator_codes'] ?? []),
                fn (mixed $code): bool => is_string($code) && trim($code) !== ''));
            if ($data['status'] === 'found' && $unconfirmedCodes !== []) {
                $progress->info('⚠️ Не найдено ни на одной странице: '.implode(', ', $unconfirmedCodes)
                    .'. Продолжаю поиск по ближайшему товару: '.($data['title'] ?? $data['model'] ?? 'без названия')
                    .'. Код оставлю в карточке как указанный тобой.');
            }

            if ($data['status'] === 'found') {
                // Already checked inside RecoverableProductResearch's callback,
                // so an unusable found response is repaired before reaching here.
                $primarySource = $this->primarySource($data['sources']);
                $data['primary_source_url'] = $primarySource['url'];
                // Direct image URLs are optional seeds. stage() owns the one
                // Playwright pass after the draft is persisted, so research
                // never repeats the browser work or requires gallery URLs.
                $data['image_urls'] = $sourcePriority->sortUrls([
                    ...($primarySource['image_urls'] ?? []),
                    ...($data['image_urls'] ?? []),
                ], $data['brand'] ?? null, [$primarySource]);
            }

            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => [
                    ...$response->usage->toArray(),
                    'web_search_calls' => count($webSearchItems),
                ],
                'completed_at' => now(),
            ]);

            if ($data['status'] !== 'found') {
                $notFound = [
                    'ok' => true,
                    'status' => $data['status'],
                    'web_search_calls' => count($webSearchItems),
                ];
                $reason = trim((string) ($data['research_notes'] ?? ''));

                if ($reason !== '') {
                    $notFound['reason'] = mb_substr($reason, 0, 2000);
                }
                $progress->done('Исследование завершено: подходящий товар не подтверждён. Запрос #'.$this->update->id);

                return $this->json($notFound);
            }

            $catalogIdentity = app(ProductCatalogIdentity::class);
            $existingProduct = $catalogIdentity->find($data, $data['condition'] ?? 'new', activeOnly: true);

            if ($this->isCancelled()) {
                return $this->cancelledResult($run, $response, count($webSearchItems));
            }

            if ($existingProduct) {
                // Deterministic, not left to the AI's own wording: the exact
                // same identity match that would otherwise create a new
                // draft instead shows the real published card straight away
                // - a free-text "you already have this" without the photo
                // and current data is a worse answer than the card itself.
                try {
                    ($this->productCards ?? app(ProductCardPresenter::class))->send(
                        app(TelegramClient::class),
                        (string) $this->update->chat_id,
                        $existingProduct,
                        '✅ Такой товар уже есть в каталоге',
                        condition: $data['condition'] ?? 'new',
                        variantId: $catalogIdentity->variant($existingProduct, $data['specifications'] ?? [], $data['condition'] ?? 'new', activeOnly: true, model: $data['model'] ?? null)?->id,
                        telegramUserId: $this->update->telegram_user_id,
                    );
                } catch (Throwable $exception) {
                    report($exception);
                }

                return $this->json([
                    'ok' => true,
                    'status' => 'already_in_catalog',
                    'product_id' => $existingProduct->id,
                    'title' => $existingProduct->title,
                    'active' => $existingProduct->is_active,
                ]);
            }

            $result = $this->recordOperation(
                $this->update,
                class_basename(self::class),
                'create_product_draft',
                ['query' => $query],
                function () use ($data, $run, $officialGallery): array {
                    if ($this->isCancelled()) {
                        return ['ok' => true, 'status' => 'cancelled'];
                    }
                    $draft = ProductDraft::query()->create([
                        'telegram_update_id' => $this->update->id,
                        'ai_run_id' => $run->id,
                        'requested_by_telegram_user_id' => $this->update->telegram_user_id,
                        'title' => $data['title'],
                        'brand' => $data['brand'],
                        'model' => $data['model'],
                        'product_type' => $data['product_type'] ?? null,
                        'category' => $data['category'] ?? null,
                        'color' => $data['color'],
                        'condition' => $data['condition'] ?? 'new',
                        'description' => $data['description'],
                        'research_notes' => $data['research_notes'],
                        'specifications' => $data['specifications'],
                        'specification_evidence' => $data['specification_evidence'],
                        'sources' => $data['sources'],
                        'primary_source_url' => $data['primary_source_url'],
                        'official_source_url' => $data['official_source_url'],
                        'image_urls' => $data['image_urls'],
                        'confidence' => $data['confidence'],
                    ]);
                    if ($officialGallery !== null && $officialGallery['specification_text'] !== '') {
                        // The database datasheet becomes an observed page the
                        // assembly agent may cite or ignore like any other.
                        ProductSourcePageEvidence::query()->updateOrCreate(
                            ['product_draft_id' => $draft->id, 'url_hash' => hash('sha256', ProductImageStorage::normalizeCandidateUrl($officialGallery['source']['url']))],
                            ['url' => $officialGallery['source']['url'], 'specification_text' => $officialGallery['specification_text'], 'captured_via' => 'icecat'],
                        );
                    }

                    return [
                        'status' => 'found',
                        'draft_id' => $draft->id,
                        'title' => $draft->title,
                        'brand' => $draft->brand,
                        'model' => $draft->model,
                        'category' => $draft->category,
                        'color' => $draft->color,
                        'description' => $draft->description,
                        'research_notes' => $draft->research_notes,
                        'specifications' => $draft->specifications,
                        'sources' => $draft->sources,
                        'primary_source_url' => $draft->primary_source_url,
                        'official_source_url' => $draft->official_source_url,
                        'image_urls' => $draft->image_urls,
                        'confidence' => (float) $draft->confidence,
                    ];
                },
                ProductDraft::class,
            );

            $draft = ProductDraft::query()->find($result['draft_id'] ?? null);

            if ($this->isCancelled()) {
                return $this->cancelledResult($run);
            }

            if ($draft && $draft->status === 'pending_review' && ! $draft->images_staged_at) {
                $imageStageTimeout = $timeBudget->timeoutFor(
                    $this->update->id,
                    $settings->browserScoutTimeoutSeconds()
                        + ($settings->galleryRecipeTimeoutSeconds() * (1 + $settings->galleryTrainingMaxRounds()))
                        + ($settings->browserTimeoutSeconds() * $settings->galleryTrainingMaxRounds())
                        + $settings->imageDiscoveryTimeoutSeconds()
                        + (2 * $settings->imageVisionTimeoutSeconds()),
                );
                $progress->step(
                    '2/3 · Playwright-галерея, загрузка и резервный поиск',
                    $imageStageTimeout,
                    $draft->primary_source_url ?: null,
                );
                $imageCount = ($this->imageStorage ?? app(ProductImageStorage::class))->stage(
                    $draft,
                    fn (string $message) => $this->isCancelled() ? null : $progress->info($message),
                );
            } else {
                $imageCount = $draft?->media()->count() ?? 0;
            }

            if ($this->isCancelled()) {
                return $this->cancelledResult($run);
            }

            $gallerySearchStopReason = $draft?->fresh()->gallery_search_stop_reason;
            $textOnlyResult = $draft?->status === 'pending_review'
                && $imageCount === 0
                && ! $draft->reconciliationPending();
            // A stop is only ever a dead end when the request itself decided
            // there is nothing left worth trying (identity conflict, no
            // candidates at all). Running out of money/time, or trying every
            // known and AI-discovered source without a match, are all
            // resumable - a fresh continuation cycle gets its own budget and
            // explores genuinely new candidates, so none of them should
            // leave the user with a dead draft and no way to keep going.
            $galleryPaused = $draft
                && $imageCount === 0
                && ! $draft->hasReadyVerifiedPartial()
                && in_array($gallerySearchStopReason, ['cost_budget', 'time_budget', 'exhausted'], true);

            if ($galleryPaused) {
                // The photo pass chose its stop reason before final assembly.
                // Assembly may have consumed the last money since that check.
                // Preserve that real limit instead of queueing another cycle.
                if ($gallerySearchStopReason === 'exhausted'
                    && app(ProductSearchCostBudget::class)->exceeded($this->update->id)) {
                    $gallerySearchStopReason = 'cost_budget';
                    $draft->update(['gallery_search_stop_reason' => $gallerySearchStopReason]);
                }
                $automaticallyContinuing = $gallerySearchStopReason === 'exhausted'
                    || ($gallerySearchStopReason === 'time_budget'
                        && app(ProductSearchCostBudget::class)->hasRemaining($this->update->id));
                // 'exhausted' means every source tried so far came up empty,
                // but the request's own time/cost budget (tracked per
                // telegram_update_id) is not actually used up yet - a
                // continuation cycle reusing the same update id still shares
                // that same remaining allowance, so it can be queued right
                // away instead of waiting for the user to press the button.
                // cost_budget/time_budget means the allowance itself is
                // gone, so only an explicit user action (fresh budget) can
                // resume it.
                if ($gallerySearchStopReason === 'exhausted') {
                    $progress->warning('Все известные и найденные AI-поиском источники проверены без результата. Автоматически продолжаю поиск новым циклом.');
                    ContinueDraftGallerySearch::dispatch(
                        $draft->id,
                        (string) $this->update->chat_id,
                        $this->update->id,
                        $this->update->id,
                    )->afterCommit();
                } elseif ($gallerySearchStopReason === 'time_budget'
                    && app(ProductSearchCostBudget::class)->hasRemaining($this->update->id)) {
                    $progress->info('Технический запуск завершён; автоматически продолжаю поиск с оставшимся денежным бюджетом.');
                } else {
                    $progress->warning('Лимит текущего запуска достигнут. Черновик сохранён, поиск можно продолжить отдельным бюджетным циклом.');
                }

                // Assembly already produced a verified text card. A photo
                // budget stop must not hide it, but a live continuation still
                // owns delivery until the existing search policy finishes.
                if ($automaticallyContinuing || ! $textOnlyResult) {
                    return $this->json([
                        ...$result,
                        'ok' => true,
                        'status' => $automaticallyContinuing ? 'gallery_search_continuing' : 'gallery_search_paused',
                        'draft_id' => $draft->id,
                        'image_count' => 0,
                        'message' => $automaticallyContinuing
                            ? 'Информация сохранена; поиск фотографий продолжается автоматически с тем же денежным бюджетом.'
                            : 'Информация сохранена; поиск фотографий приостановлен и доступен для продолжения.',
                    ]);
                }
            }

            $result['image_count'] = $imageCount;
            $draft->refresh();
            $sourceUrl = trim((string) $draft->primary_source_url);
            if ($draft->reconciliationPending()) {
                $previewCount = count($draft->verifiedPhotoPaths());
                $partialPhotos = $previewCount > 0;
                if ($partialPhotos) {
                    $progress->warning("Частичный результат: фото — {$previewCount}. Отправляю найденное; характеристики ещё не согласованы.");
                } else {
                    $progress->failed('Сборка карточки не завершена', 'Пригодных фото пока нет. Черновик сохранён для продолжения.');
                }

                return $this->json([
                    'ok' => true, 'status' => $partialPhotos ? 'partial_result' : 'card_assembly_paused',
                    'draft_id' => $draft->id, 'image_count' => $imageCount,
                    'partial_photo_result' => $partialPhotos,
                    'partial_photo_count' => $previewCount,
                    'ready_for_approval' => false,
                    'stop_reason' => $draft->gallery_search_stop_reason,
                    'message' => $partialPhotos
                        ? 'Поиск сохранил фотографии. Сохрани draft_id: приложение отправит найденные фото как частичный результат. Характеристики ещё не согласованы; не объявлять готовый товар, не предлагать публикацию и не запускать поиск заново в этом запросе.'
                        : 'Поиск не завершил сборку согласованной карточки. Не предлагать принятие или отдельную ручную сверку.',
                ]);
            }
            // Assembly may have corrected the initial research values. Return
            // the final card, not the obsolete pre-gallery snapshot.
            $result = [...$result, ...$draft->only([
                'title', 'model', 'color', 'description', 'specifications',
                'sources', 'primary_source_url', 'image_urls',
            ]), 'ready_for_approval' => $imageCount > 0, 'text_only_result' => $textOnlyResult];
            $progress->done(
                '3/3 · черновик #'.$draft->id.' готов, фото: '.$imageCount
                .($sourceUrl !== '' ? "\n🔗 {$sourceUrl}" : ''),
            );

            return $this->json($result);
        } catch (Throwable $exception) {
            if ($this->isCancelled()) {
                $run->update(['error' => mb_substr($exception->getMessage(), 0, 5000)]);

                return $this->cancelledResult($run);
            }
            $errors = app(AiErrorPresenter::class);
            app(ProductSourceAttemptRecorder::class)->record([
                'telegram_update_id' => $this->update->id,
                'actor' => 'server', 'phase' => 'product_pipeline', 'action' => 'pipeline_exception',
                'status' => 'interrupted', 'decision' => 'exception',
                'message' => mb_substr($exception->getMessage(), 0, 5000),
                'output' => ['ai_run_id' => $run->id, 'exception_class' => $exception::class],
            ]);

            if ($errors->present($exception)['retryable']) {
                $retryAfter = $errors->retryAfterSeconds($exception);
                $progress->info($retryAfter !== null
                    ? 'Временная пауза AI; поиск продолжится автоматически примерно через '.$retryAfter.' сек.'
                    : 'Временная пауза AI; поиск продолжится автоматически.');
            } else {
                $progress->failed('Поиск товара', $exception);
            }
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);

            if ($exception instanceof InvalidResearchResponse) {
                $notes = $exception->evidence['result']['research_notes'] ?? null;

                return $this->json(['ok' => false, 'status' => 'research_interrupted', 'retryable' => false,
                    'reason' => $exception->reason,
                    'blocked_by' => array_keys((array) ($exception->evidence['validation_errors'] ?? [])),
                    'agent_comment' => is_string($notes) && $notes !== '' ? mb_substr($notes, 0, 800) : null,
                    'message' => 'Не удалось восстановить полный ответ поиска в текущем запуске. Это не означает, что товар не найден. Полученные данные сохранены в журнале. Если blocked_by называет selected_identity, объясни, что модель или комплектация не подтверждена однозначно, и перескажи agent_comment своими словами. Не запускай поиск повторно без запроса пользователя.']);
            }

            throw $exception;
        }
    }

    private function isCancelled(): bool
    {
        return app(ProductSearchCancellation::class)->isCancelled($this->update->id);
    }

    private function cancelledResult(?AiRun $run = null, ?AgentResponse $response = null, int $webSearchCalls = 0): string
    {
        if ($run) {
            $metadata = $response ? [
                'invocation_id' => $response->invocationId,
                'usage' => [...$response->usage->toArray(), 'web_search_calls' => $webSearchCalls],
            ] : [];
            $run->update([
                ...$metadata,
                'status' => 'completed',
                'response' => ['status' => 'cancelled'],
                'completed_at' => now(),
            ]);
        }

        return $this->json(['ok' => true, 'status' => 'cancelled']);
    }

    /** @return array<string, mixed> */
    private function validateCorrectedResearchResponse(array $response): array
    {
        $normalized = ($this->responseNormalizer ?? app(ProductResearchResponseNormalizer::class))
            ->normalize($response);

        $data = Validator::make($normalized, [
            'status' => ['required', 'in:found,not_found,unresolved'],
            'title' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'in:laptop,desktop,component,other'],
            'category' => ['nullable', 'string', Rule::in(
                Category::query()->where('is_active', true)->pluck('slug'),
            )],
            'color' => ['nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'required', Rule::in(ProductCondition::VALUES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'research_notes' => ['nullable', 'string', 'max:5000'],
            'unconfirmed_operator_codes' => ['sometimes', 'array', 'max:10'],
            'code_lookup_verdict' => ['sometimes', 'nullable', 'array'],
            'code_lookup_verdict.decision' => ['required_with:code_lookup_verdict', 'in:used,ignored,not_applicable'],
            'code_lookup_verdict.reason' => ['required_with:code_lookup_verdict', 'string', 'max:500'],
            'unconfirmed_operator_codes.*' => ['string', 'max:100'],
            'selected_identity' => ['sometimes', 'nullable', 'array:basis,model_scope,observed_model,source_url,source_excerpt,reason'],
            'selected_identity.basis' => ['required_with:selected_identity', 'in:commercial_code,full_model'],
            'selected_identity.model_scope' => ['required_with:selected_identity', 'in:single_product,product_family,unknown'],
            'selected_identity.observed_model' => ['required_with:selected_identity', 'string', 'max:255'],
            'selected_identity.source_url' => ['required_with:selected_identity', 'url:http,https', 'max:2048'],
            'selected_identity.source_excerpt' => ['required_with:selected_identity', 'string', 'max:800'],
            'selected_identity.reason' => ['required_with:selected_identity', 'string', 'max:800'],
            'specifications' => ['present', 'array', 'max:100'],
            'specifications.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'specifications.*.name' => ['required', 'string', 'max:255'],
            'specifications.*.value' => ['required', 'string', 'max:2000'],
            'specifications.*.group' => ['sometimes', 'nullable', 'array:id,name,name_cs'],
            'specifications.*.source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'specifications.*.source_excerpt' => ['nullable', 'string', 'max:800'],
            ...ProductTextEvidenceContract::rules('specification_evidence', optional: true),
            'sources' => ['present', 'array', 'max:'.ProductResearchAgent::MAX_SOURCES],
            'sources.*.title' => ['required', 'string', 'max:500'],
            'sources.*.url' => ['required', 'url:http,https', 'max:2048'],
            'sources.*.type' => ['nullable', 'in:manufacturer,retailer,marketplace,review,database,web'],
            'sources.*.roles' => ['sometimes', 'required', 'array', 'min:1', 'max:3'],
            'sources.*.roles.*' => ['required', Rule::in(ResearchSourceRoles::VALUES)],
            'sources.*.applicability_reason' => ['sometimes', 'required', 'string', 'max:800'],
            'sources.*.image_urls' => ['present', 'array', 'max:10'],
            'sources.*.image_urls.*' => ['url:http,https', 'max:2048'],
            'primary_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'official_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'image_urls' => ['present', 'array', 'max:10'],
            'image_urls.*' => ['url:http,https', 'max:2048'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ])->validate();

        foreach ($data['sources'] as $index => $source) {
            $roles = $source['roles'] ?? [];
            if (in_array('exact_photo', $roles, true) && in_array('compatible_photo_candidate', $roles, true)) {
                throw ValidationException::withMessages(["sources.{$index}.roles" => 'A photo candidate cannot be both exact and a different potentially compatible configuration. Keep the observed source and choose its actual candidate role; neither role verifies the gallery.']);
            }
        }

        if ($data['status'] === 'unresolved') {
            throw ValidationException::withMessages(['selected_identity' => 'Identity/configuration remains unresolved: '
                .($data['research_notes'] ?? 'Explain the concrete missing evidence.').' Retain useful observations and repair only the missing evidence; this is not proof that the product does not exist.']);
        }

        if ($data['status'] === 'found') {
            Validator::make($data, [
                'title' => ['required', 'string'],
                'sources' => ['required', 'array', 'min:1'],
                'primary_source_url' => ['required', 'url:http,https'],
            ])->validate();
            $sources = app(ProductSourcePriority::class)->sortSources($data['sources'], $data['brand'] ?? null);
            Validator::make(['primary_source' => $this->primarySource($sources)], [
                'primary_source' => ['required', 'array'],
            ], ['primary_source.required' => 'The found result has no unblocked source that identifies the exact product. Keep observed URLs and facts and add the page or record that shows this exact product; do not invent or relabel its source type.'])->validate();

            $category = app(ProductCategoryResolver::class)->resolve($data['product_type'] ?? null, $data['category'] ?? null);
            $issues = app(ProductResearchConfigurationGuard::class)->issues($data);
            if (! $category) {
                $issues[] = 'Category and product_type are incompatible or ambiguous in the live category configuration.';
            }
            if ($issues !== []) {
                // These failures share the same retained response, budget and
                // cancellation-aware recovery as transport/schema failures.
                throw ValidationException::withMessages(['configuration' => $issues]);
            }
            $data['category'] = $category->slug;
        }

        return $data;
    }

    /**
     * The official datasheet of the selected product, offered as the first
     * photo candidate - only when its code matches what research selected.
     * Photo applicability and Vision still judge every frame; a mismatch or a
     * database failure leaves the ordinary sources exactly as they were.
     *
     * @return array{source: array<string, mixed>, specification_text: string}|null
     */
    private function officialGallery(ProductCodeLookup $lookup, array $data, array $queryGtins): ?array
    {
        $specs = collect($data['specifications'] ?? []);
        $codes = $specs->whereIn('key', ['sku', 'mpn', 'part_number', 'product_code'])->pluck('value')
            ->push($data['model'] ?? null)->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => trim($code))
            // The datasheet's code has no localisation suffix (AA3B6UA#ABA → AA3B6UA).
            ->flatMap(fn (string $code): array => str_contains($code, '#') ? [$code, trim((string) strstr($code, '#', true))] : [$code])
            ->filter()->unique()->values()->all();
        $gtins = $specs->whereIn('key', ['ean', 'upc', 'gtin', 'gtin13', 'ean13'])->pluck('value')
            ->map(fn (mixed $code): string => preg_replace('/\D/', '', (string) $code))
            ->filter(fn (string $code): bool => ProductCodeLookup::validGtin($code))->merge($queryGtins)->unique()->values()->all();
        $normal = static fn (string $code): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        // A datasheet found by asking with the selected code is that code's datasheet,
        // even when Icecat files it under another part number (live #456: ASUS model
        // GA403UV-G14.R94060 → part 90NR0I02-M003W0, seven photos missed).
        // The datasheet's own title must name that code too.
        $samePart = fn (array $result): bool => in_array($normal((string) ($result['part_number'] ?? '')), array_map($normal, $codes), true)
            || (filled($result['queried_part'] ?? null) && in_array($normal((string) $result['queried_part']), array_map($normal, $codes), true)
                && str_contains($normal((string) ($result['title'] ?? '')), $normal((string) $result['queried_part'])));
        $sameGtin = fn (array $result): bool => array_intersect(array_map(fn ($gtin) => ltrim((string) $gtin, '0'), (array) ($result['gtins'] ?? [])),
            array_map(fn ($gtin) => ltrim((string) $gtin, '0'), $gtins)) !== [];
        $matches = fn (array $result): bool => $samePart($result) || $sameGtin($result);
        try {
            $found = collect($lookup->lookup($gtins, $data['brand'] ?? null, $codes, $this->update->id, 'official_gallery'))
                ->filter(fn (array $result): bool => $result['source'] === 'icecat' && $result['found'] && ($result['page_url'] ?? null) !== null);
            // The selected part number is the strongest tie. A barcode may belong to
            // another product, so the part code still gets its own look first.
            $match = $found->first($samePart);
            if ($match === null && $found->isNotEmpty() && filled($data['brand'] ?? null)) {
                foreach (array_slice($codes, 0, 3) as $part) {
                    $result = $lookup->icecat(null, (string) $data['brand'], $part, $this->update->id, 'official_gallery');
                    if ($result['found'] && ($result['page_url'] ?? null) !== null && $samePart($result)) {
                        $match = $result;
                        break;
                    }
                }
            }
            $match ??= $found->first($sameGtin) ?? $found->first();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
        if ($match === null) {
            return null;
        }
        $sameCode = $matches($match);
        $images = collect($match['images'])->filter(fn (array $image): bool => $image['width'] >= 700 || $image['width'] === 0)
            ->pluck('url')->take(10)->values()->all();
        app(ProductSourceAttemptRecorder::class)->record(['telegram_update_id' => $this->update->id,
            'product_url' => $match['page_url'], 'actor' => 'server', 'phase' => 'code_lookup', 'action' => 'offer_official_gallery',
            'status' => $sameCode && $images !== [] ? 'completed' : 'skipped',
            'decision' => $sameCode ? ($images === [] ? 'no_large_images' : 'queued_first') : 'code_mismatch',
            'output' => ['part_number' => $match['part_number'], 'gtins' => $match['gtins'], 'images' => count($images), 'selected_codes' => $codes]]);
        if (! $sameCode || $images === []) {
            return null;
        }

        return ['source' => ['title' => 'Icecat datasheet: '.$match['title'], 'url' => $match['page_url'], 'type' => 'web',
            'image_urls' => $images, 'roles' => ['facts', 'exact_photo'], '_official_gallery' => true,
            'applicability_reason' => 'Official datasheet (Icecat) for the same part number/GTIN as the selected product.'],
            'specification_text' => implode("\n", [$match['title'], ...$match['specifications']])];
    }

    private function primarySource(array $sources): ?array
    {
        $discovery = app(ProductImageCandidateDiscovery::class);

        // web is an unclassified candidate, not a verified retailer. Page and
        // gallery checks still decide applicability; no paid relabel is needed.
        // Preserve the existing classified-source fallback without promoting
        // generic web documents or text-only review/database entries into it.
        $sources = array_values(array_filter($sources, fn (array $source): bool => ResearchSourceRoles::mayIdentifySelectedProduct($source)));

        // A product page is preferred, never required (operator, 2026-10-21): live
        // #629 had only the Icecat datasheet for an old regional SKU, and a card
        // built from it beats "could not confirm". Any source that identifies the
        // exact product may carry the card; the gallery is searched separately.
        return collect($sources)->first(fn (array $source): bool => in_array($source['type'] ?? null, ['manufacturer', 'retailer', 'marketplace', 'web'], true)
            && $discovery->looksLikeHtmlProductPage((string) ($source['url'] ?? '')))
            ?? collect($sources)->first(fn (array $source): bool => in_array($source['type'] ?? null, ['manufacturer', 'retailer', 'marketplace'], true))
            ?? collect($sources)->first(fn (array $source): bool => filled($source['url'] ?? null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description(
                'The product exactly as the user described it: brand, model, and the specs/identifiers they '
                .'actually stated. Do not add a model year, generation, suffix, or any other identifying detail '
                .'the user did not mention - an invented detail can steer the downstream search toward the wrong '
                .'product. Do not repeat the same term multiple times or wrap it in quotes.'
            ),
        ];
    }
}
