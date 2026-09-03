<?php

namespace Tests\Feature;

use App\Models\ProductSourceAttempt;
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

    public function test_work_a_running_bot_is_busy_with_is_left_alone(): void
    {
        // Run next to a working bot - one stray double-click on the launcher -
        // this used to delete the job it was busy with and cancel the request
        // it was answering. A held job on its own proves nothing (that is what
        // a killed run leaves behind); recent writes do, because only a live
        // process produces them.
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        $live = TelegramUpdate::query()->create([
            'update_id' => 5003,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'processing',
        ]);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $live->id,
            'domain' => 'shop.example',
            'product_url' => 'https://shop.example/p/1',
            'actor' => 'playwright',
            'phase' => 'gallery_training',
            'action' => 'execute_recipe',
            'status' => 'completed',
            'decision' => 'ready_for_selection',
        ]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('processing', $live->refresh()->status);
    }

    public function test_a_held_job_with_no_sign_of_life_is_still_cleared(): void
    {
        // The shape a killed worker leaves: the job stays held forever and
        // nothing has been written since. Refusing here would let yesterday's
        // request come back to life on the next start.
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->subHour()->timestamp,
            'available_at' => now()->subHour()->timestamp,
            'created_at' => now()->subHour()->timestamp,
        ]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
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
