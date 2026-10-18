<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_nothing_when_the_queue_is_empty(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake();

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_for_a_job_that_has_not_been_waiting_long(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake();
        $this->queueJob(now()->subSeconds(30)->timestamp);
        TelegramChatState::query()->create(['chat_id' => '98765', 'telegram_user_id' => '12345']);

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_alerts_the_admin_when_a_job_has_been_stuck_too_long(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->queueJob(now()->subMinutes(5)->timestamp);
        TelegramChatState::query()->create(['chat_id' => '98765', 'telegram_user_id' => '12345']);

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '98765'
            && str_contains((string) $request['text'], 'воркер'));
    }

    public function test_it_does_not_spam_a_second_alert_within_the_cooldown(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->queueJob(now()->subMinutes(5)->timestamp);
        TelegramChatState::query()->create(['chat_id' => '98765', 'telegram_user_id' => '12345']);

        $this->artisan('queue:health-check')->assertSuccessful();
        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_alert_cooldown_clears_once_the_queue_drains(): void
    {
        Cache::put('queue-health:alerted', true, 900);

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertFalse(Cache::has('queue-health:alerted'));
    }

    public function test_a_stopped_worker_does_not_receive_repeated_alerts_after_fifteen_minutes(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->queueJob(now()->subMinutes(5)->timestamp);
        TelegramChatState::query()->create(['chat_id' => '98765', 'telegram_user_id' => '12345']);

        $this->artisan('queue:health-check')->assertSuccessful();
        $this->travel(95)->minutes();
        $this->artisan('queue:health-check')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_a_photo_marked_processed_without_any_outcome_is_reported_once_for_resending(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $lost = TelegramUpdate::create([
            'update_id' => 92001, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'payload' => ['message' => ['photo' => [['file_id' => 'f']]]], 'status' => 'received',
        ]);
        $lost->forceFill(['processed_at' => now()->subMinutes(9), 'created_at' => now()->subMinutes(9)])->save();

        $this->artisan('queue:health-check')->assertSuccessful();
        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => $request['chat_id'] === '98765'
            && str_contains((string) $request['text'], 'фото') && str_contains((string) $request['text'], 'ещё раз'));
        $this->assertNotNull($lost->fresh()->payload['_lost_update_alerted_at']);
    }

    public function test_a_received_message_still_waiting_in_the_queue_is_not_lost(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->queueJob(now()->subMinutes(9)->timestamp);
        DB::table('jobs')->update(['reserved_at' => now()->timestamp]);
        TelegramUpdate::query()->update(['status' => 'received', 'created_at' => now()->subMinutes(9)]);

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function queueJob(int $createdAt): void
    {
        $update = TelegramUpdate::create([
            'update_id' => 91000 + TelegramUpdate::count(), 'chat_id' => '98765',
            'telegram_user_id' => '12345', 'payload' => [], 'status' => 'pending',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => json_encode(['displayName' => ProcessTelegramMessage::class,
                'data' => ['command' => serialize(new ProcessTelegramMessage($update->id))]]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }
}
