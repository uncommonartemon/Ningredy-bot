<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAllowlistSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_allowlist_takes_priority_over_env_config(): void
    {
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['11111'],
        ]);
        AppSetting::put(AppSetting::TELEGRAM_ALLOWED_USER_IDS, "12345\n99999");

        // Allowed by the database list, not by the (different) env list.
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9101,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 12345],
                'chat' => ['id' => 12345],
                'text' => 'Найди ноутбук',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 9101, 'status' => 'received']);

        // Rejected: only in the env list, which the database list now overrides.
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9102,
            'message' => [
                'message_id' => 2,
                'from' => ['id' => 11111],
                'chat' => ['id' => 11111],
                'text' => 'Найди ноутбук',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 9102, 'status' => 'rejected']);
    }

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
