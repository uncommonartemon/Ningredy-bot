<?php

namespace Tests\Feature;

use App\Services\BotHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token', 'queue.default' => 'database']);
    }

    public function test_a_registered_address_that_no_longer_answers_is_reported_as_down(): void
    {
        // Telegram only reports a delivery error once it has had something to
        // deliver. Between the tunnel dropping and the next message the
        // registered address is dead and nothing knows it - so the address is
        // asked directly rather than taken on Telegram's word.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
                'url' => 'https://gone.ngrok-free.app/api/telegram/webhook',
                'pending_update_count' => 0,
            ]]),
            'gone.ngrok-free.app/*' => Http::response('', 502),
        ]);

        $webhook = $this->checkNamed('webhook');

        $this->assertSame('down', $webhook['state']);
        $this->assertStringContainsString('не отвечает', $webhook['detail']);
        $this->assertStringContainsString('ngrok', $webhook['hint']);
    }

    public function test_our_own_webhook_refusing_an_unsigned_request_is_the_healthy_answer(): void
    {
        // 403 is this application's controller turning away a request with no
        // secret header. A 200 would mean something else is on that address.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
                'url' => 'https://live.ngrok-free.app/api/telegram/webhook',
                'pending_update_count' => 0,
            ]]),
            'live.ngrok-free.app/*' => Http::response('', 403),
        ]);

        $this->assertSame('up', $this->checkNamed('webhook')['state']);
    }

    public function test_an_unregistered_webhook_says_so_and_says_what_to_do(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => '']])]);

        $webhook = $this->checkNamed('webhook');

        $this->assertSame('down', $webhook['state']);
        $this->assertStringContainsString('telegram:set-webhook', $webhook['hint']);
    }

    public function test_a_job_nobody_has_picked_up_reads_as_a_worker_that_is_not_running(): void
    {
        // The most common way a handover looks broken, and the hint carries the
        // command that fixes it - the person reading this has no terminal open.
        Http::fake(['*' => Http::response('', 403)]);
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time() - 3600,
            'created_at' => time() - 3600,
        ]);

        $worker = $this->checkNamed('worker');

        $this->assertSame('down', $worker['state']);
        $this->assertStringContainsString('queue:work', $worker['hint']);
    }

    public function test_a_quiet_queue_is_not_a_dead_worker(): void
    {
        Http::fake(['*' => Http::response('', 403)]);
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->assertSame('up', $this->checkNamed('worker')['state']);
    }

    /** @return array{key: string, label: string, state: string, detail: string, hint: string} */
    private function checkNamed(string $key): array
    {
        return collect(app(BotHealth::class)->checks())->firstWhere('key', $key);
    }
}
