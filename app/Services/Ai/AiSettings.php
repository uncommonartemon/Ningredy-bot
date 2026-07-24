<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use App\Models\TelegramChatState;

class AiSettings
{
    /** @var array<string, string> */
    public const MODELS = [
        'gpt-5.4' => 'GPT-5.4',
        'gpt-5.4-mini' => 'GPT-5.4 mini',
        'gpt-5.4-nano' => 'GPT-5.4 nano',
        'gpt-5.1' => 'GPT-5.1',
        'gpt-5' => 'GPT-5',
        'gpt-5-mini' => 'GPT-5 mini',
        'gpt-5-nano' => 'GPT-5 nano',
        'gpt-4.1' => 'GPT-4.1',
        'gpt-4.1-mini' => 'GPT-4.1 mini',
        'gpt-4.1-nano' => 'GPT-4.1 nano',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
        'o3' => 'o3',
        'o4-mini' => 'o4-mini',
    ];

    public function model(): ?string
    {
        $model = AppSetting::valueFor('ai.model');

        return array_key_exists($model, self::MODELS) ? $model : null;
    }

    public function saveModel(?string $model): void
    {
        $selectedModel = array_key_exists($model, self::MODELS) ? $model : null;

        if ($this->model() !== $selectedModel) {
            TelegramChatState::query()->update(['conversation_id' => null]);
        }

        AppSetting::put('ai.model', $selectedModel);
    }

    public function providerFor(string $role): string
    {
        return 'openai';
    }

    public function modelFor(string $role): string
    {
        // The admin's global model override is a chat/vision-capable model
        // choice; roles with a fundamentally different model family (voice
        // transcription, image generation) must never inherit it.
        if (in_array($role, ['voice_transcription', 'image_upscale'], true)) {
            return (string) config("services.{$role}.model");
        }

        return $this->model() ?: (string) config("services.{$role}.model");
    }
}
