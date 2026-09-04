<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Products\ProductCategoryResolver;
use App\Services\Products\ProductIdentityKey;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPublicDescription;
use App\Services\Products\ProductResearchConfigurationGuard;
use App\Services\Products\ProductResearchResponseNormalizer;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Products\ProductSourcePriority;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
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
        $researchPrompt = $query
            .($blockedDomains === [] ? '' : PHP_EOL.'Never search or return these blocked domains: '.implode(', ', $blockedDomains))
            .($provenDomains === [] ? '' : PHP_EOL.'This catalog already knows how to read product pages on these shops: '
                .implode(', ', $provenDomains)
                .'. When one of them genuinely sells the exact requested product, include its product page among the sources.'
                .' Never return a page from them for a different model, and never let this list narrow the search:'
                .' an exact match anywhere else is always worth more than a familiar domain.');
        $run = AiRun::query()->create([
            'telegram_update_id' => $this->update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $researchPrompt,
            'started_at' => now(),
        ]);

        try {
            $activity = [];
            $lastActivityWrite = 0.0;
            $reportedActivity = [];
            $webSearchItems = [];
            $recordActivity = function (StreamEvent $event) use (
                $run,
                $progress,
                &$activity,
                &$lastActivityWrite,
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

                if ($traceable && count($activity) < 50) {
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
                $response = ($this->streamingResearch ?? app(StreamingProductResearch::class))->prompt(
                    ProductResearchAgent::make(),
                    $researchPrompt,
                    $provider,
                    $model,
                    $researchTimeout,
                    $researchIdleTimeout,
                    $recordActivity,
                    fn (int $seconds) => $progress->info(
                        'Другой тяжёлый AI-этап ещё выполняется; этот поиск продолжится примерно через '.$seconds.' сек.',
                    ),
                );
            } finally {
                $progress->clearCancelButton();
            }

            // Cancellation is still checked after the provider returns; the
            // streamed transport now limits silent waits, but it cannot
            // interrupt local post-processing already in progress.
            if ($this->update->fresh()?->cancel_requested_at !== null) {
                $run->update(['status' => 'completed', 'response' => ['status' => 'cancelled'], 'completed_at' => now()]);
                $progress->done('🚫 Поиск отменён по запросу');

                return $this->json(['ok' => true, 'status' => 'cancelled']);
            }

            $progress->info('Результат поиска получен; проверяю совпадение и источники.');
            $normalizedResponse = ($this->responseNormalizer ?? app(ProductResearchResponseNormalizer::class))
                ->normalize($response->toArray());
            $data = Validator::make($normalizedResponse, [
                'status' => ['required', 'in:found,not_found'],
                'title' => ['nullable', 'string', 'max:255'],
                'brand' => ['nullable', 'string', 'max:255'],
                'model' => ['nullable', 'string', 'max:255'],
                'product_type' => ['nullable', 'in:laptop,desktop,component,other'],
                'category' => ['nullable', 'string', Rule::in(Category::query()->where('is_active', true)->pluck('slug'))],
                'color' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'research_notes' => ['nullable', 'string', 'max:5000'],
                'specifications' => ['present', 'array', 'max:100'],
                'specifications.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
                'specifications.*.name' => ['required', 'string', 'max:255'],
                'specifications.*.value' => ['required', 'string', 'max:2000'],
                'sources' => ['present', 'array', 'max:20'],
                'sources.*.title' => ['required', 'string', 'max:500'],
                'sources.*.url' => ['required', 'url:http,https', 'max:2048'],
                'sources.*.type' => ['nullable', 'in:manufacturer,retailer,marketplace,review,database,web'],
                'sources.*.image_urls' => ['present', 'array', 'max:10'],
                'sources.*.image_urls.*' => ['url:http,https', 'max:2048'],
                'primary_source_url' => ['nullable', 'url:http,https', 'max:2048'],
                'official_source_url' => ['nullable', 'url:http,https', 'max:2048'],
                'image_urls' => ['present', 'array', 'max:10'],
                'image_urls.*' => ['url:http,https', 'max:2048'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
            ])->validate();

            if ($data['status'] === 'found') {
                $resolvedCategory = app(ProductCategoryResolver::class)->resolve(
                    $data['product_type'] ?? null,
                    $data['category'] ?? null,
                );

                if ($resolvedCategory) {
                    $data['category'] = $resolvedCategory->slug;
                }
            }

            $configurationGuard = app(ProductResearchConfigurationGuard::class);
            $configurationIssues = $configurationGuard->issues($data);
            if (($data['status'] ?? null) === 'found'
                && ! app(ProductCategoryResolver::class)->resolve(
                    $data['product_type'] ?? null,
                    $data['category'] ?? null,
                )) {
                $configurationIssues[] = 'Category and product_type are incompatible or ambiguous in the live category configuration.';
            }
            $correctionAttempt = 0;

            while (
                $configurationIssues !== []
                && $correctionAttempt < 2
                && $timeBudget->canStart($this->update->id, 30)
                && ! app(ProductSearchCostBudget::class)->exceeded($this->update->id)
            ) {
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
                $correctionAttempt++;
                $progress->info('Проверка обнаружила смешение конфигураций или категории; возвращаю агенту точные причины для самостоятельного исправления.');
                $correctionPrompt = $researchPrompt.PHP_EOL
                    .'The previous result failed deterministic catalog validation. Return a completely corrected result for one exact sellable configuration. '
                    .'Do not defend or summarize the previous result. Validation issues: '
                    .implode(' | ', $configurationIssues).PHP_EOL
                    .'Previous result: '.(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
                $correctionTimeout = $timeBudget->timeoutFor(
                    $this->update->id,
                    $settings->productResearchTimeoutSeconds(),
                );
                $run = AiRun::query()->create([
                    'telegram_update_id' => $this->update->id,
                    'provider' => $provider,
                    'model' => $model,
                    'status' => 'running',
                    'prompt' => $correctionPrompt,
                    'started_at' => now(),
                ]);
                $response = app(OpenAiHeavyOperationGate::class)->run(
                    $provider,
                    $correctionTimeout,
                    fn () => ProductResearchAgent::make()->prompt(
                        $correctionPrompt,
                        provider: $provider,
                        model: $model,
                        timeout: $correctionTimeout,
                    ),
                );
                $webSearchItems = [];
                $data = $this->validateCorrectedResearchResponse($response->toArray());
                $resolvedCategory = app(ProductCategoryResolver::class)->resolve(
                    $data['product_type'] ?? null,
                    $data['category'] ?? null,
                );
                if ($resolvedCategory) {
                    $data['category'] = $resolvedCategory->slug;
                }
                $configurationIssues = $configurationGuard->issues($data);
                if (($data['status'] ?? null) === 'found' && ! $resolvedCategory) {
                    $configurationIssues[] = 'Category and product_type remain incompatible or ambiguous.';
                }
            }

            if ($configurationIssues !== []) {
                $data['status'] = 'not_found';
                $data['research_notes'] = 'Exact configuration validation did not pass after automatic correction: '
                    .implode(' | ', $configurationIssues);
            }

            $publicDescription = $this->publicDescription ?? app(ProductPublicDescription::class);
            $normalizedDescription = $publicDescription->normalize($data);
            $data['description'] = $normalizedDescription['description'];
            $data['research_notes'] = $normalizedDescription['research_notes'];
            $reportedSources = $data['sources'];
            $data['sources'] = $sourcePriority->sortSources($reportedSources, $data['brand']);
            $attemptRecorder = app(ProductSourceAttemptRecorder::class);
            foreach ($reportedSources as $source) {
                $blocked = $sourcePriority->isBlockedUrl((string) $source['url']);
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

            if ($data['status'] === 'found') {
                Validator::make($data, [
                    'title' => ['required', 'string'],
                    'sources' => ['required', 'array', 'min:1'],
                    'primary_source_url' => ['required', 'url:http,https'],
                ])->validate();

                $primarySource = collect($data['sources'])->first(
                    fn (array $source): bool => in_array($source['type'] ?? null, ['manufacturer', 'retailer', 'marketplace'], true),
                );

                Validator::make(['primary_source' => $primarySource], [
                    'primary_source' => ['required', 'array'],
                    'primary_source.type' => ['required', 'in:manufacturer,retailer,marketplace'],
                ])->validate();

                $data['primary_source_url'] = $primarySource['url'];
                // Direct image URLs are optional seeds. stage() owns the one
                // Playwright pass after the draft is persisted, so research
                // never repeats the browser work or requires gallery URLs.
                $data['image_urls'] = $sourcePriority->sortUrls([
                    ...($primarySource['image_urls'] ?? []),
                    ...($data['image_urls'] ?? []),
                ], $data['brand'], [$primarySource]);
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

                return $this->json($notFound);
            }

            $existingProduct = Product::query()
                ->where('canonical_key', ProductIdentityKey::for($data['brand'], $data['model'], $data['title']))
                ->first();

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
                function () use ($data, $run): array {
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
                        'description' => $data['description'],
                        'research_notes' => $data['research_notes'],
                        'specifications' => $data['specifications'],
                        'sources' => $data['sources'],
                        'primary_source_url' => $data['primary_source_url'],
                        'official_source_url' => $data['official_source_url'],
                        'image_urls' => $data['image_urls'],
                        'confidence' => $data['confidence'],
                    ]);

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
                    fn (string $message) => $progress->info($message),
                );
            } else {
                $imageCount = $draft?->media()->count() ?? 0;
            }

            $gallerySearchStopReason = $draft?->fresh()->gallery_search_stop_reason;
            // A stop is only ever a dead end when the request itself decided
            // there is nothing left worth trying (identity conflict, no
            // candidates at all). Running out of money/time, or trying every
            // known and AI-discovered source without a match, are all
            // resumable - a fresh continuation cycle gets its own budget and
            // explores genuinely new candidates, so none of them should
            // leave the user with a dead draft and no way to keep going.
            $galleryPaused = $draft
                && $imageCount === 0
                && in_array($gallerySearchStopReason, ['cost_budget', 'time_budget', 'exhausted'], true);

            if ($galleryPaused) {
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
                } else {
                    $progress->warning('Лимит текущего запуска достигнут. Черновик сохранён, поиск можно продолжить отдельным бюджетным циклом.');
                }

                return $this->json([
                    ...$result,
                    'ok' => true,
                    'status' => 'gallery_search_paused',
                    'draft_id' => $draft->id,
                    'image_count' => 0,
                    'message' => 'Информация сохранена; поиск фотографий приостановлен и доступен для продолжения.',
                ]);
            }

            if ($draft && $imageCount === 0) {
                $progress->warning('Все карточки и резервный поиск проверены: подходящая галерея не найдена.');
                $draft->update([
                    'status' => 'rejected',
                    'reviewed_at' => now(),
                    'rejection_reason' => 'Не удалось получить и проверить единую галерею из выбранной карточки товара.',
                ]);

                return $this->json([
                    'ok' => true,
                    'status' => 'gallery_not_found',
                    'draft_id' => null,
                    'title' => $draft->title,
                    'message' => 'Точная информация найдена, но ни одна единая галерея магазина не прошла загрузку и проверку.',
                ]);
            }

            $result['image_count'] = $imageCount;
            $draft->refresh();
            $sourceUrl = trim((string) $draft->primary_source_url);
            $progress->done(
                '3/3 · черновик #'.$draft->id.' готов, фото: '.$imageCount
                .($sourceUrl !== '' ? "\n🔗 {$sourceUrl}" : ''),
            );

            return $this->json($result);
        } catch (Throwable $exception) {
            $errors = app(AiErrorPresenter::class);

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

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function validateCorrectedResearchResponse(array $response): array
    {
        $normalized = ($this->responseNormalizer ?? app(ProductResearchResponseNormalizer::class))
            ->normalize($response);

        return Validator::make($normalized, [
            'status' => ['required', 'in:found,not_found'],
            'title' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'in:laptop,desktop,component,other'],
            'category' => ['nullable', 'string', Rule::in(
                Category::query()->where('is_active', true)->pluck('slug'),
            )],
            'color' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'research_notes' => ['nullable', 'string', 'max:5000'],
            'specifications' => ['present', 'array', 'max:100'],
            'specifications.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'specifications.*.name' => ['required', 'string', 'max:255'],
            'specifications.*.value' => ['required', 'string', 'max:2000'],
            'sources' => ['present', 'array', 'max:20'],
            'sources.*.title' => ['required', 'string', 'max:500'],
            'sources.*.url' => ['required', 'url:http,https', 'max:2048'],
            'sources.*.type' => ['nullable', 'in:manufacturer,retailer,marketplace,review,database,web'],
            'sources.*.image_urls' => ['present', 'array', 'max:10'],
            'sources.*.image_urls.*' => ['url:http,https', 'max:2048'],
            'primary_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'official_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'image_urls' => ['present', 'array', 'max:10'],
            'image_urls.*' => ['url:http,https', 'max:2048'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ])->validate();
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
