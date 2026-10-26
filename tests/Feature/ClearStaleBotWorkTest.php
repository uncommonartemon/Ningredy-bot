<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClearStaleBotWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_startup_drops_queued_work_left_by_the_previous_run(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'assistant',
            'payload' => json_encode(['data' => ['commandName' => ProcessTelegramMessage::class]]),
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
            'payload' => json_encode(['data' => ['commandName' => ProcessTelegramMessage::class]]),
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
            'payload' => json_encode(['data' => ['commandName' => ProcessTelegramMessage::class]]),
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

    public function test_a_search_the_restart_interrupted_is_shown_to_the_operator_as_its_card(): void
    {
        // Live #139, #176, #177: the bot was restarted mid-search and the chat
        // went quiet - the request vanished. What was found is the card, with
        // its "continue" button, and the reason names the restart.
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
        Storage::fake('public');
        $update = TelegramUpdate::query()->create(['update_id' => 5004, 'telegram_user_id' => '123', 'chat_id' => '900',
            'text' => 'Lenovo Legion 5', 'payload' => [], 'status' => 'processing']);
        $research = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test',
            'status' => 'running', 'prompt' => 'research', 'started_at' => now()->subMinutes(5), 'activity' => ['stage' => 'research']]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $research->id, 'requested_by_telegram_user_id' => '123',
            'title' => 'Lenovo Legion 5', 'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop',
            'primary_source_url' => 'https://shop.example/legion', 'specifications_reconciled_source_url' => 'https://shop.example/legion',
            'images_staged_at' => now(), 'gallery_status' => 'complete', 'description' => 'Verified.',
            'specifications' => [], 'sources' => [], 'image_urls' => []]);
        Storage::disk('public')->put("drafts/{$draft->id}/1.jpg", 'photo');
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/1.jpg", 'checksum' => hash('sha256', 'photo'),
            'width' => 1200, 'height' => 900, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame('completed', $update->refresh()->status);
        $this->assertNotNull($update->processed_at);
        $this->assertSame('stalled', $draft->refresh()->gallery_search_stop_reason, 'The card keeps its "continue search" button.');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'перезапущен')
            && str_contains((string) ($request['text'] ?? ''), 'Сохранено фото: 1'));
    }

    public function test_a_request_interrupted_before_its_draft_is_named_so_it_can_be_sent_again(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
        $update = TelegramUpdate::query()->create(['update_id' => 5005, 'telegram_user_id' => '123', 'chat_id' => '900',
            'text' => 'HP Victus 15-fb2000', 'payload' => [], 'status' => 'processing']);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame('cancelled', $update->refresh()->status);
        $this->assertNotNull($update->processed_at);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'перезапущен')
            && str_contains((string) ($request['text'] ?? ''), 'HP Victus 15-fb2000'));
    }

    public function test_a_button_press_left_in_flight_is_cancelled_without_a_word(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
        $update = TelegramUpdate::query()->create(['update_id' => 5006, 'telegram_user_id' => '123', 'chat_id' => '900',
            'text' => null, 'payload' => ['callback_query' => ['id' => '1', 'data' => 'draft:approve:1']], 'status' => 'processing']);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertSame('cancelled', $update->refresh()->status);
        Http::assertNothingSent();
    }
}
