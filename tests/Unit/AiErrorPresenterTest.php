<?php

namespace Tests\Unit;

use App\Models\AiRun;
use App\Services\Ai\AiErrorPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiErrorPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_model_error_points_to_model_settings(): void
    {
        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('404 The model `deepseek-chet` does not exist'));

        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('модель', $result['message']);
        $this->assertStringContainsString('Настройки → AI', $result['message']);
    }

    public function test_rejected_key_mentions_admin_panel(): void
    {
        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('401 Unauthorized: invalid api key'));

        $this->assertStringContainsString('Настройки → AI', $result['message']);
    }

    public function test_reference_appends_provider_and_model_context(): void
    {
        $update = \App\Models\TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'test',
            'payload' => ['update_id' => 2001],
            'status' => 'received',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'status' => 'failed',
            'prompt' => 'test',
            'started_at' => now(),
        ]);

        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('401 Unauthorized'), $run->id);

        $this->assertStringContainsString("AI-{$run->id}", $result['message']);
        $this->assertStringContainsString('Провайдер: deepseek', $result['message']);
        $this->assertStringContainsString('модель: deepseek-chat', $result['message']);
    }
}
