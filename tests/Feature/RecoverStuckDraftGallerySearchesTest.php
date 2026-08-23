<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverStuckDraftGallerySearchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resumes_a_draft_stuck_since_its_job_was_killed_mid_search(): void
    {
        Queue::fake();
        $draft = $this->draft(imagesStagedAt: null, createdMinutesAgo: 90);

        $this->artisan('gallery:recover-stuck-drafts')->assertSuccessful();

        Queue::assertPushed(
            ContinueDraftGallerySearch::class,
            fn (ContinueDraftGallerySearch $job): bool => $job->draftId === $draft->id
                && $job->chatId === '100'
                && $job->telegramUpdateId === $draft->telegram_update_id
                && $job->expectedDraftTelegramUpdateId === $draft->telegram_update_id,
        );
    }

    public function test_it_ignores_a_draft_that_has_not_been_stuck_long_enough(): void
    {
        Queue::fake();
        $this->draft(imagesStagedAt: null, createdMinutesAgo: 10);

        $this->artisan('gallery:recover-stuck-drafts')->assertSuccessful();

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_it_ignores_a_draft_whose_search_already_completed_a_pass(): void
    {
        Queue::fake();
        $this->draft(imagesStagedAt: now(), createdMinutesAgo: 90);

        $this->artisan('gallery:recover-stuck-drafts')->assertSuccessful();

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_it_stops_retrying_after_the_recovery_attempt_cap(): void
    {
        Queue::fake();
        $draft = $this->draft(imagesStagedAt: null, createdMinutesAgo: 90);
        Cache::put("draft-gallery-recovery-attempts:{$draft->id}", 3, now()->addHours(6));

        $this->artisan('gallery:recover-stuck-drafts')->assertSuccessful();

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_it_does_not_double_dispatch_while_a_continuation_is_already_queued(): void
    {
        Queue::fake();
        $draft = $this->draft(imagesStagedAt: null, createdMinutesAgo: 90);
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, now()->addMinutes(35));

        $this->artisan('gallery:recover-stuck-drafts')->assertSuccessful();

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    private function draft(?Carbon $imagesStagedAt, int $createdMinutesAgo): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(100_000, 999_999),
            'telegram_user_id' => '1',
            'chat_id' => '100',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'status' => 'pending_review',
            'requested_by_telegram_user_id' => '1',
            'title' => 'Test product',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
            'confidence' => 1,
            'images_staged_at' => $imagesStagedAt,
        ]);

        DB::table('product_drafts')->where('id', $draft->id)->update([
            'created_at' => now()->subMinutes($createdMinutesAgo),
        ]);

        return $draft->fresh();
    }
}
