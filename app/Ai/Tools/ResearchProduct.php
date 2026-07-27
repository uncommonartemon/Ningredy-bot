<?php

namespace App\Ai\Tools;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductIdentityKey;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPublicDescription;
use App\Services\Products\ProductResearchResponseNormalizer;
use App\Services\Products\ProductSourcePriority;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
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
    ) {}

    public function description(): Stringable|string
    {
        return 'Search the internet for a real product, verify its data and create a pending catalog draft. Use for explicit web searches or after local catalog search returns no suitable match.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request->string('query'));
        $progress = new TelegramProgressReporter(app(TelegramClient::class), (string) $this->update->chat_id);
        $progress->step('1/4 · поиск точной карточки и характеристик', 240, $query);
        $provider = app(AiSettings::class)->providerFor('product_research');
        $model = app(AiSettings::class)->modelFor('product_research');
        $run = AiRun::query()->create([
            'telegram_update_id' => $this->update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $query,
            'started_at' => now(),
        ]);

        try {
            $response = ProductResearchAgent::make()->prompt($query, provider: $provider, model: $model, timeout: 240);
            $progress->info('Карточка получена; проверяю модель, цвет и источники.');
            $normalizedResponse = ($this->responseNormalizer ?? app(ProductResearchResponseNormalizer::class))
                ->normalize($response->toArray());
            $data = Validator::make($normalizedResponse, [
                'status' => ['required', 'in:found,needs_clarification,not_found'],
                'clarification_question' => ['nullable', 'string', 'max:1000'],
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

            $publicDescription = $this->publicDescription ?? app(ProductPublicDescription::class);
            $normalizedDescription = $publicDescription->normalize($data);
            $data['description'] = $normalizedDescription['description'];
            $data['research_notes'] = $normalizedDescription['research_notes'];
            $sourcePriority = $this->sourcePriority ?? app(ProductSourcePriority::class);
            $data['sources'] = $sourcePriority->sortSources($data['sources'], $data['brand']);

            if ($data['status'] === 'found') {
                Validator::make($data, [
                    'title' => ['required', 'string'],
                    'sources' => ['required', 'array', 'min:1'],
                    'primary_source_url' => ['required', 'url:http,https'],
                ])->validate();

                $reportedPrimaryUrl = $data['primary_source_url'];
                $primarySource = collect($data['sources'])->first(
                    fn (array $source): bool => in_array($source['type'] ?? null, ['manufacturer', 'retailer', 'marketplace'], true),
                );

                Validator::make(['primary_source' => $primarySource], [
                    'primary_source' => ['required', 'array'],
                    'primary_source.type' => ['required', 'in:manufacturer,retailer,marketplace'],
                ])->validate();

                $data['primary_source_url'] = $primarySource['url'];
                $progress->step('2/4 · галерея страницы и AI-переобучение при необходимости', 480, parse_url($data['primary_source_url'], PHP_URL_HOST) ?: null);
                $resolvedGallery = $this->imageResolver->resolve(
                    [$primarySource],
                    10,
                    function (string $level, string $message) use ($progress): void {
                        match ($level) {
                            'error' => $progress->warning($message),
                            'warning', 'blocked' => $progress->warning($message),
                            default => $progress->info($message),
                        };
                    },
                );
                $legacyPrimaryImages = $reportedPrimaryUrl === $data['primary_source_url'] ? $data['image_urls'] : [];
                $data['image_urls'] = $sourcePriority->sortUrls([
                    ...($primarySource['image_urls'] ?? []),
                    ...$legacyPrimaryImages,
                    ...$resolvedGallery,
                ], $data['brand'], [$primarySource]);
            }

            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            if ($data['status'] !== 'found') {
                return $this->json([
                    'ok' => true,
                    'status' => $data['status'],
                    'clarification_question' => $data['clarification_question'],
                ]);
            }

            $existingProduct = Product::query()
                ->where('canonical_key', ProductIdentityKey::for($data['brand'], $data['model'], $data['title']))
                ->first();

            if ($existingProduct) {
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
                $progress->step('3/4 · загрузка, резервный поиск и vision-проверка фото', 360);
                $imageCount = ($this->imageStorage ?? app(ProductImageStorage::class))->stage(
                    $draft,
                    fn (string $message) => $progress->info($message),
                );
            } else {
                $imageCount = $draft?->media()->count() ?? 0;
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
            $progress->done("4/4 · черновик #{$draft->id} готов, фото: {$imageCount}");

            return $this->json($result);
        } catch (Throwable $exception) {
            $progress->failed('Поиск товара', $exception);
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }
}
