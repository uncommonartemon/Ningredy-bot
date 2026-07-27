<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ProductGalleryRecipeTrainer
{
    public function __construct(
        private readonly BrowserProductGalleryExtractor $browser,
        private readonly AiSettings $settings,
    ) {}

    /**
     * @param  null|callable(string, string): void  $debug
     * @return array<int, string>
     */
    public function train(
        string $url,
        string $trigger = 'automatic',
        ?callable $debug = null,
        bool $force = false,
    ): array {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return [];
        }

        $lock = Cache::lock('gallery-recipe-training:'.sha1($host), 360);

        if (! $lock->get()) {
            $debug?->__invoke('warning', "AI-тренер {$host} уже запущен другим воркером.");

            return [];
        }

        try {
            $recipe = ProductGalleryRecipe::query()->firstOrCreate(
                ['domain' => $host, 'path_pattern' => '*'],
                ['status' => 'learning'],
            );

            if (! $force
                && $recipe->last_failure_at?->isAfter(now()->subMinutes(10))
                && $recipe->status === 'learning') {
                $debug?->__invoke('warning', "AI-тренер {$host}: действует 10-минутная пауза после ошибки.");

                return [];
            }

            $provider = $this->settings->providerFor('gallery_recipe_training');
            $model = $this->settings->modelFor('gallery_recipe_training');
            $version = ProductGalleryRecipeVersion::query()->create([
                'product_gallery_recipe_id' => $recipe->id,
                'domain' => $host,
                'product_url' => $url,
                'trigger' => $trigger,
                'status' => 'scouting',
                'provider' => $provider,
                'model' => $model,
                'previous_recipe' => $recipe->recipe,
            ]);

            $debug?->__invoke('step', "AI-тренер: Playwright собирает DOM-структуру {$host} (без скриншота).");
            $scout = $this->browser->scout($url, $debug);

            if (($scout['scout']['fragments'] ?? []) === []) {
                throw new RuntimeException('Playwright не получил полезную DOM-структуру страницы.');
            }

            $version->update(['status' => 'training', 'scout' => $scout['scout']]);
            $prompt = json_encode([
                'url' => $url,
                'current_recipe' => $recipe->recipe,
                'page' => $scout['scout'],
                'diagnostics' => $scout['diagnostics'] ?? [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $debug?->__invoke('step', "AI-тренер: {$model} строит безопасный JSON-рецепт.");
            $response = ProductGalleryRecipeTrainerAgent::make()->prompt(
                $prompt ?: '{}',
                provider: $provider,
                model: $model,
                timeout: (int) config('services.gallery_recipe_training.timeout', 180),
            );
            $candidate = $this->validateRecipe($response->toArray());
            $version->update(['status' => 'testing', 'recipe' => $candidate]);

            $debug?->__invoke('step', 'AI-тренер: проверяю новый рецепт на этой же карточке.');
            $candidateResult = $this->browser->executeRecipe($url, $candidate, 20, $debug);
            $candidateImages = $candidateResult['images'] ?? [];
            $oldImages = [];

            if (is_array($recipe->recipe) && $recipe->recipe !== []) {
                $oldImages = $this->browser->executeRecipe($url, $recipe->recipe, 20)['images'] ?? [];
            }

            $score = $this->score($candidateResult);
            $promote = count($candidateImages) >= 2
                && (count($oldImages) < 2 || count($candidateImages) >= count($oldImages));
            $version->update([
                'status' => $promote ? 'promoted' : 'rejected',
                'result' => [
                    'candidate_count' => count($candidateImages),
                    'previous_count' => count($oldImages),
                    'diagnostics' => $candidateResult['diagnostics'] ?? [],
                ],
                'score' => $score,
                'promoted_at' => $promote ? now() : null,
                'error' => $promote ? null : 'Новый рецепт не превзошёл рабочую версию или вернул меньше двух фото.',
            ]);

            if (! $promote) {
                $recipe->update([
                    'failure_count' => $recipe->failure_count + 1,
                    'last_failure_at' => now(),
                    'last_error' => $version->error,
                ]);
                $debug?->__invoke('warning', 'Новый рецепт отклонён; старая версия оставлена без изменений.');

                return $oldImages;
            }

            $recipe->update([
                'recipe' => $candidate,
                'status' => 'active',
                'success_count' => $recipe->success_count + 1,
                'last_success_at' => now(),
                'last_error' => null,
            ]);
            $debug?->__invoke('done', 'AI-рецепт проверен и опубликован. Фото: '.count($candidateImages).'.');

            return $candidateImages;
        } catch (Throwable $exception) {
            isset($version) && $version->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
            isset($recipe) && $recipe->update([
                'status' => $recipe->recipe ? $recipe->status : 'learning',
                'failure_count' => $recipe->failure_count + 1,
                'last_failure_at' => now(),
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);
            $debug?->__invoke('error', 'AI-тренер: '.$exception->getMessage());
            Log::warning('Gallery recipe training failed.', ['host' => $host, 'error' => $exception->getMessage()]);

            return [];
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function validateRecipe(array $data): array
    {
        $data = Validator::make($data, [
            'pre_click_selectors' => ['present', 'array', 'max:5'],
            'collect_selectors' => ['present', 'array', 'max:12'],
            'thumbnail_selectors' => ['present', 'array', 'max:8'],
            'open_selectors' => ['present', 'array', 'max:5'],
            'next_selectors' => ['present', 'array', 'max:5'],
            'pre_click_selectors.*' => ['string', 'max:300'],
            'collect_selectors.*' => ['string', 'max:300'],
            'thumbnail_selectors.*' => ['string', 'max:300'],
            'open_selectors.*' => ['string', 'max:300'],
            'next_selectors.*' => ['string', 'max:300'],
            'attributes' => ['present', 'array', 'max:12'],
            'attributes.*' => ['regex:/^(?:src|href|srcset|data-[a-z0-9_-]+)$/i', 'max:80'],
            'max_thumbnail_clicks' => ['required', 'integer', 'between:0,20'],
            'max_next_clicks' => ['required', 'integer', 'between:0,15'],
            'wait_after_click_ms' => ['required', 'integer', 'between:50,1000'],
        ])->validate();

        foreach (['pre_click_selectors', 'collect_selectors', 'thumbnail_selectors', 'open_selectors', 'next_selectors'] as $key) {
            $data[$key] = collect($data[$key] ?? [])
                ->filter(fn (mixed $selector): bool => is_string($selector) && $this->safeSelector($selector))
                ->map(fn (string $selector): string => trim($selector))
                ->unique()->values()->all();
        }

        $data['attributes'] = collect($data['attributes'] ?? [])
            ->map(fn (string $attribute): string => strtolower(trim($attribute)))
            ->unique()->values()->all();

        if ($data['collect_selectors'] === []) {
            throw new RuntimeException('AI не вернул безопасный селектор сбора изображений.');
        }

        return $data;
    }

    private function safeSelector(string $selector): bool
    {
        return trim($selector) !== ''
            && ! str_contains($selector, "\0")
            && ! preg_match('/(?:javascript:|https?:|file:|xpath|script\b|iframe\b)/i', $selector);
    }

    private function score(array $result): float
    {
        $count = count($result['images'] ?? []);
        $dom = (int) data_get($result, 'diagnostics.dom_candidates', 0);

        return min(100, ($count * 10) + min(20, $dom));
    }
}
