<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPublicUrlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_command_returns_current_database_proxy_without_ai(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, 'https://current.ngrok-free.app');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9300,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => 12345],
                'chat' => ['id' => 12345],
                'text' => '/url',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 9300, 'status' => 'command']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'https://current.ngrok-free.app/catalog'));
    }
}
