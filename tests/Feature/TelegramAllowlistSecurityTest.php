<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAllowlistSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_allowlist_blocks_every_telegram_user(): void
    {
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => [],
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9100,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 12345],
                'chat' => ['id' => 12345],
                'text' => 'Найди ноутбук',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])
            ->assertOk()
            ->assertJson(['ignored' => true]);

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 9100, 'status' => 'rejected']);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }
}
