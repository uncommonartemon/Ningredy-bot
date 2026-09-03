<?php

namespace Tests\Feature;

use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QueueHealthCheckReleasesAbandonedJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_left_reserved_by_a_dead_worker_is_released_immediately(): void
    {
        // Real crash (2026-09-02): the worker was killed by memory exhaustion
        // mid-search. Its job stayed reserved, the health check only looked at
        // unreserved jobs, and retry_after (37 min, necessarily longer than the
        // job's own 35-minute ceiling) meant nothing moved until then.
        Http::fake();
        $update = $this->update(901);
        $jobId = $this->reservedJob($update->id, reservedMinutesAgo: 20);

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertNull(DB::table('jobs')->where('id', $jobId)->value('reserved_at'));
    }

    public function test_a_search_that_is_still_writing_progress_is_left_alone(): void
    {
        // The same reservation age, but the search is demonstrably alive - it
        // must never be handed to a second worker, which would double its cost
        // and duplicate the draft.
        Http::fake();
        $update = $this->update(902);
        $jobId = $this->reservedJob($update->id, reservedMinutesAgo: 20);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $update->id,
            'domain' => 'example.com',
            'product_url' => 'https://example.com/p/1',
            'actor' => 'app',
            'phase' => 'gallery_training',
            'action' => 'propose_recipe',
            'status' => 'completed',
        ]);

        $this->artisan('queue:health-check')->assertSuccessful();

        $this->assertNotNull(DB::table('jobs')->where('id', $jobId)->value('reserved_at'));
    }

    public function test_a_job_queued_behind_a_busy_worker_is_not_reported_as_an_unattended_queue(): void
    {
        // The release above frees a job that then waits its turn, and the old
        // "queue is not being processed" alert measured age since creation - so
        // it told the admin to restart a worker that was demonstrably alive and
        // busy with the very next job.
        Http::fake();
        $update = $this->update(903);
        $this->reservedJob($update->id, reservedMinutesAgo: 1);
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessTelegramMessage', 'data' => []]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        $this->artisan('queue:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function update(int $updateId): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => $updateId,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'processing',
        ]);
    }

    private function reservedJob(int $telegramUpdateId, int $reservedMinutesAgo): int
    {
        $command = 'O:36:"App\\Jobs\\ProcessTelegramMessage":3:{s:16:"telegramUpdateId";i:'
            .$telegramUpdateId.';s:5:"queue";s:9:"assistant";s:11:"afterCommit";b:1;}';

        return (int) DB::table('jobs')->insertGetId([
            'queue' => 'assistant',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ProcessTelegramMessage',
                'data' => ['commandName' => 'App\\Jobs\\ProcessTelegramMessage', 'command' => $command],
            ]),
            'attempts' => 1,
            'reserved_at' => now()->subMinutes($reservedMinutesAgo)->timestamp,
            'available_at' => now()->subMinutes($reservedMinutesAgo + 1)->timestamp,
            'created_at' => now()->subMinutes($reservedMinutesAgo + 1)->timestamp,
        ]);
    }
}
