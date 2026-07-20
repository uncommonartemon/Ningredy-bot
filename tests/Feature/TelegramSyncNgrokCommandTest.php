<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSyncNgrokCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_ngrok_and_synchronizes_the_webhook(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.webhook_secret', 'test-secret');

        Http::fake([
            'http://127.0.0.1:4040/api/tunnels' => Http::response([
                'tunnels' => [[
                    'public_url' => 'https://new.ngrok-free.app',
                    'config' => ['addr' => 'http://localhost:8000'],
                ]],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:sync-ngrok')
            ->expectsOutput('Telegram webhook synchronized: https://new.ngrok-free.app/api/telegram/webhook')
            ->assertSuccessful();

        $this->assertSame(
            'https://new.ngrok-free.app',
            AppSetting::valueFor(AppSetting::TELEGRAM_PROXY_URL),
        );
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://new.ngrok-free.app/api/telegram/webhook');
    }
}
