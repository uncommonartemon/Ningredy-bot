<?php

namespace Tests\Feature;

use App\Ai\Tools\GetSystemStatus;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetSystemStatusToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_exposes_current_public_url_from_database(): void
    {
        AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, 'https://current.ngrok-free.app/');

        $status = json_decode(
            (string) (new GetSystemStatus)->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('https://current.ngrok-free.app', $status['public_url']);
        $this->assertSame('https://current.ngrok-free.app/catalog', $status['catalog_url']);
        $this->assertSame('https://current.ngrok-free.app/admin', $status['admin_url']);
        $this->assertSame(
            'https://current.ngrok-free.app/api/telegram/webhook',
            $status['telegram_webhook_url'],
        );
    }
}
