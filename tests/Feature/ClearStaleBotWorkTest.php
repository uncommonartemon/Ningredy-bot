<?php

namespace Tests\Feature;

use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClearStaleBotWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_startup_drops_queued_work_left_by_the_previous_run(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        $stuck = TelegramUpdate::query()->create([
            'update_id' => 5001,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'processing',
        ]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('cancelled', $stuck->refresh()->status);
        $this->assertNotNull($stuck->processed_at);
    }

    public function test_a_finished_request_keeps_its_own_status(): void
    {
        // Terminal history is audit data - only never-finished requests are the
        // leftovers of a killed run.
        $done = TelegramUpdate::query()->create([
            'update_id' => 5002,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'completed',
            'processed_at' => now()->subDay(),
        ]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame('completed', $done->refresh()->status);
    }
}
