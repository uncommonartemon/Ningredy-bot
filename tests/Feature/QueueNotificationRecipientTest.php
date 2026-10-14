<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueNotificationRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_and_outage_notices_go_to_the_job_owner(): void
    {
        $jobId = $this->fixture(reserved: true);
        $this->artisan('queue:health-check')->assertSuccessful();
        $this->assertNull(DB::table('jobs')->where('id', $jobId)->value('reserved_at'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '10001'
            && str_contains($request['text'], 'возвращена в очередь'));
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '10001'
            && str_contains($request['text'], 'воркер'));
        Http::assertNotSent(fn (Request $request): bool => $request['chat_id'] === '10002');
    }

    public function test_unreserved_job_notifies_its_owner_once(): void
    {
        $this->fixture(reserved: false);
        $this->artisan('queue:health-check')->assertSuccessful();
        $this->travel(95)->minutes();
        $this->artisan('queue:health-check')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '10001');
        Http::assertNotSent(fn (Request $request): bool => $request['chat_id'] === '10002');
    }

    public function test_missing_job_owner_does_not_fall_back_to_another_chat(): void
    {
        $this->fixture(reserved: false);
        TelegramUpdate::query()->delete();
        $this->artisan('queue:health-check')->assertSuccessful();
        Http::assertNothingSent();
    }

    private function fixture(bool $reserved): int
    {
        config(['services.telegram.bot_token' => 'isolated-test-token']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramChatState::create(['chat_id' => '10001', 'telegram_user_id' => '20001'])
            ->forceFill(['updated_at' => now()->subMinutes(30)])->save();
        TelegramChatState::create(['chat_id' => '10002', 'telegram_user_id' => '20002']);
        $update = TelegramUpdate::create([
            'update_id' => 777001, 'chat_id' => '10001', 'telegram_user_id' => '20001',
            'payload' => [], 'status' => 'processing',
        ]);

        return (int) DB::table('jobs')->insertGetId([
            'queue' => 'assistant', 'attempts' => $reserved ? 1 : 0,
            'payload' => json_encode(['displayName' => ProcessTelegramMessage::class,
                'data' => ['commandName' => ProcessTelegramMessage::class,
                    'command' => serialize(new ProcessTelegramMessage($update->id))]]),
            'reserved_at' => $reserved ? now()->subMinutes(20)->timestamp : null,
            'available_at' => now()->subMinutes(21)->timestamp,
            'created_at' => now()->subMinutes(21)->timestamp,
        ]);
    }
}
