<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use App\Models\TelegramChatState;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AiSettings
{
    public const DEFAULT_MAX_SEARCH_COST_USD = 0.50;

    public const DEFAULT_SEARCH_MAX_SECONDS = 1800;

    public const DEFAULT_SEARCH_RESERVE_SECONDS = 120;

    public const DEFAULT_PRODUCT_RESEARCH_TIMEOUT_SECONDS = 900;

    public const DEFAULT_PRODUCT_RESEARCH_IDLE_TIMEOUT_SECONDS = 90;

    public const DEFAULT_IMAGE_DISCOVERY_TIMEOUT_SECONDS = 180;

    public const DEFAULT_GALLERY_RECIPE_TIMEOUT_SECONDS = 240;

    public const DEFAULT_IMAGE_VISION_TIMEOUT_SECONDS = 90;

    public const DEFAULT_BROWSER_TIMEOUT_SECONDS = 90;

    public const DEFAULT_BROWSER_SCOUT_TIMEOUT_SECONDS = 120;

    public const DEFAULT_GALLERY_TRAINING_MAX_ROUNDS = 3;

    public const DEFAULT_GALLERY_MIN_SUCCESS_COUNT = 3;

    public const DEFAULT_IMAGE_MINIMUM_WIDTH = 700;

    public const DEFAULT_IMAGE_MINIMUM_HEIGHT = 0;

    public const MIN_IMAGE_WIDTH = 100;

    public const MIN_IMAGE_HEIGHT = 0;

    public const MAX_IMAGE_DIMENSION = 4000;

    public const GALLERY_MAX_IMAGE_COUNT = 10;

    public const GALLERY_BROWSER_AUTO = 'auto';

    public const GALLERY_BROWSER_OFF = 'off';

    public const GALLERY_BROWSER_ALWAYS = 'always';

    /** @var array<string, string> */
    public const GALLERY_BROWSER_MODES = [
        self::GALLERY_BROWSER_AUTO => 'Автоматически',
        self::GALLERY_BROWSER_OFF => 'Выключен',
        self::GALLERY_BROWSER_ALWAYS => 'Всегда',
    ];

    /** @var array<string, string> */
    public const IMAGE_MODELS = [
        'gpt-image-2' => 'GPT Image 2 (лучшее качество, по умолчанию)',
        'gpt-image-1-mini' => 'GPT Image 1 Mini (дешевле, ниже качество)',
    ];

    public function __construct(private readonly AiModelCatalog $models) {}

    public function model(): ?string
    {
        $model = AppSetting::valueFor('ai.model');

        return $this->models->has('openai', $model) ? $model : null;
    }

    public function saveModel(?string $model): void
    {
        $selectedModel = $this->models->has('openai', $model) ? $model : null;

        if ($this->model() !== $selectedModel) {
            TelegramChatState::query()->update(['conversation_id' => null]);
        }

        AppSetting::put('ai.model', $selectedModel);
    }

    /**
     * The DB-stored key overrides OPENAI_API_KEY (see AppServiceProvider::boot());
     * returns null when unset so callers fall back to the .env value.
     */
    public function openAiApiKey(): ?string
    {
        $encrypted = AppSetting::valueFor('ai.openai_api_key');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasStoredOpenAiApiKey(): bool
    {
        return $this->openAiApiKey() !== null;
    }

    public function saveOpenAiApiKey(?string $key): void
    {
        $key = trim((string) $key);

        AppSetting::put('ai.openai_api_key', $key !== '' ? Crypt::encryptString($key) : null);
    }

    public function imageModel(): ?string
    {
        $model = AppSetting::valueFor('ai.image_model');

        return array_key_exists($model, self::IMAGE_MODELS) ? $model : null;
    }

    public function saveImageModel(?string $model): void
    {
        AppSetting::put('ai.image_model', array_key_exists($model, self::IMAGE_MODELS) ? $model : null);
    }

    public function galleryRecipeTrainingModel(): ?string
    {
        $model = AppSetting::valueFor('ai.gallery_recipe_training_model');

        return $this->models->has('openai', $model) ? $model : null;
    }

    public function saveGalleryRecipeTrainingModel(?string $model): void
    {
        AppSetting::put(
            'ai.gallery_recipe_training_model',
            $this->models->has('openai', $model) ? $model : null,
        );
    }

    public function maxSearchCostUsd(): float
    {
        $value = AppSetting::valueFor('ai.max_search_cost_usd');

        return is_numeric($value)
            ? max(0.0, (float) $value)
            : (float) config('product-images.max_search_cost_usd', self::DEFAULT_MAX_SEARCH_COST_USD);
    }

    public function saveMaxSearchCostUsd(?float $value): void
    {
        AppSetting::put('ai.max_search_cost_usd', $value !== null ? (string) max(0.0, $value) : null);
    }

    public function fallbackSourcesEnabled(): bool
    {
        $value = AppSetting::valueFor('ai.fallback_sources_enabled');

        return $value === null
            ? true
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function saveFallbackSourcesEnabled(bool $enabled): void
    {
        AppSetting::put('ai.fallback_sources_enabled', $enabled ? '1' : '0');
    }

    /**
     * The AI preflight's static_sufficient verdict is an estimate from raw
     * DOM markup (thumbnails/size-variants can inflate it - see the smarty.cz
     * "8 predicted, 3 real" case), not a verified count. When true, a
     * static_sufficient verdict that also reports a real gallery
     * (gallery_likely) is overridden into training a real, reusable
     * Playwright recipe instead of trusting the estimate - slower and
     * costlier per search, but the recipe is saved for reuse and the final
     * count is Vision-verified rather than guessed. Explicit user tradeoff
     * (2026-08-06); defaults to true.
     */
    public function galleryPreferPlaywrightFirst(): bool
    {
        $value = AppSetting::valueFor('ai.gallery_prefer_playwright_first');

        return $value === null
            ? true
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function saveGalleryPreferPlaywrightFirst(bool $enabled): void
    {
        AppSetting::put('ai.gallery_prefer_playwright_first', $enabled ? '1' : '0');
    }

    public function searchMaxSeconds(): int
    {
        return $this->integerSetting(
            'ai.search_max_seconds',
            (int) config('product-images.search_timing.max_seconds', self::DEFAULT_SEARCH_MAX_SECONDS),
            300,
            1800,
        );
    }

    public function saveSearchMaxSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.search_max_seconds', $seconds, 300, 1800);
    }

    public function searchReserveSeconds(): int
    {
        return $this->integerSetting(
            'ai.search_reserve_seconds',
            (int) config('product-images.search_timing.reserve_seconds', self::DEFAULT_SEARCH_RESERVE_SECONDS),
            30,
            300,
        );
    }

    public function saveSearchReserveSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.search_reserve_seconds', $seconds, 30, 300);
    }

    public function productResearchTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.product_research_timeout_seconds',
            (int) config('product-images.search_timing.product_research_timeout_seconds', self::DEFAULT_PRODUCT_RESEARCH_TIMEOUT_SECONDS),
            60,
            900,
        );
    }

    public function saveProductResearchTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.product_research_timeout_seconds', $seconds, 60, 900);
    }

    public function productResearchIdleTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.product_research_idle_timeout_seconds',
            (int) config('product-images.search_timing.product_research_idle_timeout_seconds', self::DEFAULT_PRODUCT_RESEARCH_IDLE_TIMEOUT_SECONDS),
            30,
            180,
        );
    }

    public function saveProductResearchIdleTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.product_research_idle_timeout_seconds', $seconds, 30, 180);
    }

    public function imageDiscoveryTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.image_discovery_timeout_seconds',
            (int) config('product-images.search_timing.image_discovery_timeout_seconds', self::DEFAULT_IMAGE_DISCOVERY_TIMEOUT_SECONDS),
            30,
            300,
        );
    }

    public function saveImageDiscoveryTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.image_discovery_timeout_seconds', $seconds, 30, 300);
    }

    public function galleryRecipeTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.gallery_recipe_timeout_seconds',
            (int) config('product-images.search_timing.gallery_recipe_timeout_seconds', self::DEFAULT_GALLERY_RECIPE_TIMEOUT_SECONDS),
            30,
            600,
        );
    }

    public function saveGalleryRecipeTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.gallery_recipe_timeout_seconds', $seconds, 30, 600);
    }

    public function imageVisionTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.image_vision_timeout_seconds',
            (int) config('product-images.search_timing.image_vision_timeout_seconds', self::DEFAULT_IMAGE_VISION_TIMEOUT_SECONDS),
            15,
            180,
        );
    }

    public function saveImageVisionTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.image_vision_timeout_seconds', $seconds, 15, 180);
    }

    public function browserTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.browser_timeout_seconds',
            (int) config('product-images.search_timing.browser_timeout_seconds', self::DEFAULT_BROWSER_TIMEOUT_SECONDS),
            15,
            300,
        );
    }

    public function saveBrowserTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.browser_timeout_seconds', $seconds, 15, 300);
    }

    public function browserScoutTimeoutSeconds(): int
    {
        return $this->integerSetting(
            'ai.browser_scout_timeout_seconds',
            (int) config('product-images.search_timing.browser_scout_timeout_seconds', self::DEFAULT_BROWSER_SCOUT_TIMEOUT_SECONDS),
            15,
            300,
        );
    }

    public function saveBrowserScoutTimeoutSeconds(?int $seconds): void
    {
        $this->saveIntegerSetting('ai.browser_scout_timeout_seconds', $seconds, 15, 300);
    }

    /**
     * Only a safety cap now, not the everyday operating limit - training
     * keeps going, bounded by the money/time budget, whenever cost can be
     * measured for this search (see ProductGalleryRecipeTrainer::train()).
     * This value is used only when it genuinely can't be (tests, no
     * telegram_update_id, no configured price for the model, or the budget
     * itself disabled).
     */
    public function galleryTrainingMaxRounds(): int
    {
        return $this->integerSetting(
            'ai.gallery_training_max_rounds',
            (int) config('product-images.browser_fallback.training_max_rounds', self::DEFAULT_GALLERY_TRAINING_MAX_ROUNDS),
            1,
            12,
        );
    }

    public function saveGalleryTrainingMaxRounds(?int $rounds): void
    {
        $this->saveIntegerSetting('ai.gallery_training_max_rounds', $rounds, 1, 12);
    }

    public function galleryMinSuccessCount(): int
    {
        return $this->integerSetting(
            'ai.gallery_min_success_count',
            self::DEFAULT_GALLERY_MIN_SUCCESS_COUNT,
            1,
            self::GALLERY_MAX_IMAGE_COUNT,
        );
    }

    public function saveGalleryMinSuccessCount(?int $count): void
    {
        $this->saveIntegerSetting('ai.gallery_min_success_count', $count, 1, self::GALLERY_MAX_IMAGE_COUNT);
    }

    public function imageMinimumWidth(): int
    {
        $legacyMinimum = AppSetting::valueFor('ai.image_minimum_side');

        return $this->integerSetting(
            'ai.image_minimum_width',
            is_numeric($legacyMinimum)
                ? (int) $legacyMinimum
                : (int) config('product-images.minimum_width', self::DEFAULT_IMAGE_MINIMUM_WIDTH),
            self::MIN_IMAGE_WIDTH,
            self::MAX_IMAGE_DIMENSION,
        );
    }

    public function saveImageMinimumWidth(?int $pixels): void
    {
        $this->saveIntegerSetting(
            'ai.image_minimum_width',
            $pixels,
            self::MIN_IMAGE_WIDTH,
            self::MAX_IMAGE_DIMENSION,
        );
    }

    public function imageMinimumHeight(): int
    {
        return $this->integerSetting(
            'ai.image_minimum_height',
            (int) config('product-images.minimum_height', self::DEFAULT_IMAGE_MINIMUM_HEIGHT),
            self::MIN_IMAGE_HEIGHT,
            self::MAX_IMAGE_DIMENSION,
        );
    }

    public function saveImageMinimumHeight(?int $pixels): void
    {
        $this->saveIntegerSetting(
            'ai.image_minimum_height',
            $pixels,
            self::MIN_IMAGE_HEIGHT,
            self::MAX_IMAGE_DIMENSION,
        );
    }

    public function galleryBrowserMode(): string
    {
        $mode = AppSetting::valueFor('ai.gallery_browser_mode', self::GALLERY_BROWSER_AUTO);

        return array_key_exists($mode, self::GALLERY_BROWSER_MODES)
            ? $mode
            : self::GALLERY_BROWSER_AUTO;
    }

    public function saveGalleryBrowserMode(?string $mode): void
    {
        AppSetting::put(
            'ai.gallery_browser_mode',
            array_key_exists($mode, self::GALLERY_BROWSER_MODES)
                ? $mode
                : self::GALLERY_BROWSER_AUTO,
        );
    }

    public function providerFor(string $role): string
    {
        return 'openai';
    }

    public function modelFor(string $role): string
    {
        if ($role === 'image_upscale') {
            return $this->imageModel() ?: (string) config('services.image_upscale.model');
        }

        if ($role === 'gallery_recipe_training') {
            return $this->galleryRecipeTrainingModel()
                ?: (string) config('services.gallery_recipe_training.model', 'gpt-5.4');
        }

        // The admin's global chat-model override must never apply to a
        // fundamentally different model family (voice transcription).
        if ($role === 'voice_transcription') {
            return (string) config("services.{$role}.model");
        }

        return $this->model() ?: (string) config("services.{$role}.model");
    }

    private function integerSetting(string $key, int $default, int $min, int $max): int
    {
        $value = AppSetting::valueFor($key);

        return is_numeric($value)
            ? max($min, min($max, (int) $value))
            : max($min, min($max, $default));
    }

    private function saveIntegerSetting(string $key, ?int $value, int $min, int $max): void
    {
        AppSetting::put($key, $value === null ? null : (string) max($min, min($max, $value)));
    }
}
