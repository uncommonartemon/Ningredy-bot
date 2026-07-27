<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use App\Models\TelegramChatState;

class AiSettings
{
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
}
