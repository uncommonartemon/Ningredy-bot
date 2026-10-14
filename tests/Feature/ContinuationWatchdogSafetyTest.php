<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContinuationWatchdogSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_media_continuation_is_not_requeued_as_an_assistant_request(): void
    {
        [$update, $jobId, $payload] = $this->reservedContinuation();
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $update->id,
            'domain' => 'example.com',
            'product_url' => 'https://example.com/product',
            'actor' => 'system',
            'phase' => 'gallery_search',
            'action' => 'source_started',
            'status' => 'running',
        ]);

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertContinuationUnchanged($jobId, $payload, reserved: true);
        $this->assertSame('processing', $update->fresh()->status);
        $this->assertNull($update->fresh()->processed_at);
        Http::assertNothingSent();
    }

    public function test_long_running_ai_call_keeps_media_continuation_alive_without_recent_progress(): void
    {
        [$update, $jobId, $payload] = $this->reservedContinuation();
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'running',
            'prompt' => 'test',
            'started_at' => now()->subMinutes(12),
        ]);
        DB::table('ai_runs')->where('id', $run->id)->update([
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
        ]);

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertContinuationUnchanged($jobId, $payload, reserved: true);
        Http::assertNothingSent();
    }

    public function test_dead_media_worker_releases_the_same_continuation_not_a_new_assistant_job(): void
    {
        [, $jobId, $payload] = $this->reservedContinuation();

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertContinuationUnchanged($jobId, $payload, reserved: false);
    }

    /** @return array{TelegramUpdate, int, string} */
    private function reservedContinuation(): array
    {
        Http::fake();
        $update = TelegramUpdate::query()->create([
            'update_id' => 910001,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => ['callback_query' => ['data' => 'draft:continue:1']],
            'status' => 'processing',
            'processed_at' => null,
        ]);
        $job = new ContinueDraftGallerySearch(1, '1', $update->id, 1);
        $payload = json_encode([
            'displayName' => $job::class,
            'data' => ['commandName' => $job::class, 'command' => serialize($job)],
        ], JSON_THROW_ON_ERROR);
        $jobId = DB::table('jobs')->insertGetId([
            'queue' => 'media',
            'payload' => $payload,
            'attempts' => 1,
            'reserved_at' => now()->subMinutes(20)->timestamp,
            'available_at' => now()->subMinutes(21)->timestamp,
            'created_at' => now()->subMinutes(21)->timestamp,
        ]);

        return [$update, (int) $jobId, $payload];
    }

    private function assertContinuationUnchanged(int $jobId, string $payload, bool $reserved): void
    {
        $this->assertSame(1, DB::table('jobs')->count());
        $row = DB::table('jobs')->where('id', $jobId)->first();
        $this->assertSame('media', $row->queue);
        $this->assertSame($payload, $row->payload);
        $this->assertSame($reserved, $row->reserved_at !== null);
    }
}
