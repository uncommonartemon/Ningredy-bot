<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSetWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_the_webhook_url_from_the_configured_proxy(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.proxy_url', 'https://example.ngrok-free.app/');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:set-webhook')
            ->expectsOutput('Telegram webhook registered: https://example.ngrok-free.app/api/telegram/webhook')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-token/setWebhook'
            && $request['url'] === 'https://example.ngrok-free.app/api/telegram/webhook'
            && $request['secret_token'] === 'test-secret'
        );
    }

    public function test_database_proxy_url_has_priority_over_env_config(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.webhook_secret', 'test-secret');
        config()->set('services.telegram.proxy_url', 'https://old.ngrok-free.app');
        AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, 'https://database.ngrok-free.app');

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:set-webhook')
            ->expectsOutput('Telegram webhook registered: https://database.ngrok-free.app/api/telegram/webhook')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://database.ngrok-free.app/api/telegram/webhook');
    }
}
