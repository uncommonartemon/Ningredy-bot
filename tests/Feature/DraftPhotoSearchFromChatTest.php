<?php

namespace Tests\Feature;

use App\Ai\Tools\ManageDraftPhotos;
use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Live 2026-11-06, real model: «в черновике 1 мало фото, найди ещё» wrote a characteristic,
 * because the assistant had no way to do what the card's photo buttons do.
 */
class DraftPhotoSearchFromChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 100]])]);
    }

    /** @return array{ProductDraft, TelegramUpdate} */
    private function fixtures(array $draft = [], bool $originOpen = false): array
    {
        $origin = TelegramUpdate::create(['update_id' => 992001, 'chat_id' => '98765', 'telegram_user_id' => '12345', 'payload' => [],
            'status' => $originOpen ? 'processing' : 'completed', 'processed_at' => $originOpen ? null : now()]);
        $words = TelegramUpdate::create(['update_id' => 992002, 'chat_id' => '98765', 'telegram_user_id' => '12345', 'message_id' => 7,
            'payload' => [], 'status' => 'processing', 'text' => 'мало фото, найди ещё']);
        $run = AiRun::create(['telegram_update_id' => $origin->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => 'No provider call', 'started_at' => now(), 'completed_at' => now()]);
        $row = ProductDraft::create(['telegram_update_id' => $origin->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'title' => 'WD Blue SN570', 'product_type' => 'component', 'category' => 'components', 'status' => 'pending_review',
            'specifications' => [], 'sources' => [], 'image_urls' => [], 'images_staged_at' => now(), ...$draft]);

        return [$row, $words];
    }

    private function ask(TelegramUpdate $words, array $input): array
    {
        return json_decode((string) (new ManageDraftPhotos($words))->handle(new Request($input)), true);
    }

    public function test_a_search_stopped_with_nothing_found_goes_on_under_a_request_row_of_its_own(): void
    {
        [$draft, $words] = $this->fixtures(['gallery_search_stop_reason' => 'exhausted']);

        $answer = $this->ask($words, ['draft_id' => $draft->id, 'find_more_photos' => true]);

        $this->assertTrue($answer['ok']);
        $this->assertSame('continue-search', $answer['search']);
        Queue::assertPushed(ContinueDraftGallerySearch::class, function (ContinueDraftGallerySearch $job) use ($draft, $words): bool {
            $press = TelegramUpdate::find($job->telegramUpdateId);

            // The job takes this row over and closes it; the assistant's own request stays out of it.
            return $job->draftId === $draft->id && $job->telegramUpdateId !== $words->id
                && $job->expectedDraftTelegramUpdateId === $draft->telegram_update_id
                && $press->text === "draft:continue-search:{$draft->id}" && $press->chat_id === '98765' && $press->telegram_user_id === '12345';
        });
        Queue::assertNotPushed(TopUpDraftGalleryPhotos::class);
        $this->assertSame('processing', $words->fresh()->status);
    }

    public function test_a_finished_search_is_topped_up_and_other_photos_search_without_the_old_sources(): void
    {
        [$draft, $words] = $this->fixtures();

        $this->assertSame('findmore', $this->ask($words, ['draft_id' => $draft->id, 'find_more_photos' => true])['search']);
        Queue::assertPushed(TopUpDraftGalleryPhotos::class, fn (TopUpDraftGalleryPhotos $job): bool => $job->draftId === $draft->id && $job->telegramUpdateId !== $words->id);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        Cache::forget("draft-gallery-topup:{$draft->id}:queued");

        $this->assertSame('restage', $this->ask($words, ['draft_id' => $draft->id, 'find_other_photos' => true])['search']);
        Queue::assertPushed(RestageDraftGalleryPhotos::class, fn (RestageDraftGalleryPhotos $job): bool => $job->draftId === $draft->id);
    }

    /** Operator's decision, 2026-11-11 with a link. */
    public function test_a_link_from_the_operator_starts_taking_photos_from_that_page(): void
    {
        [$draft, $words] = $this->fixtures();

        $answer = $this->ask($words, ['draft_id' => $draft->id, 'photos_from_url' => 'https://www.avadirect.com/sk-hynix-16gb/p/1']);

        $this->assertTrue($answer['ok']);
        Queue::assertPushed(TopUpDraftGalleryPhotos::class, fn (TopUpDraftGalleryPhotos $job): bool => $job->draftId === $draft->id
            && $job->operatorPage === 'https://www.avadirect.com/sk-hynix-16gb/p/1' && $job->telegramUpdateId !== $words->id);
        Queue::assertNotPushed(RestageDraftGalleryPhotos::class);
    }

    public function test_nothing_starts_twice_or_on_top_of_a_running_search(): void
    {
        [$draft, $words] = $this->fixtures();
        $first = $this->ask($words, ['draft_id' => $draft->id, 'find_more_photos' => true]);
        // The same call in the same request gets its receipt.
        Cache::forget("draft-gallery-topup:{$draft->id}:queued");
        $this->assertSame($first, $this->ask($words, ['draft_id' => $draft->id, 'find_more_photos' => true]));
        Queue::assertPushed(TopUpDraftGalleryPhotos::class, 1);

        // A button already started it.
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, 60);
        $busy = $this->ask($words, ['draft_id' => $draft->id, 'find_other_photos' => true]);
        $this->assertFalse($busy['ok']);
        $this->assertStringContainsString('still running', $busy['error']);
        Queue::assertNotPushed(RestageDraftGalleryPhotos::class);
    }

    public function test_a_draft_whose_first_search_is_still_going_is_not_started_again(): void
    {
        [$draft, $words] = $this->fixtures(['images_staged_at' => null], originOpen: true);

        $this->assertFalse($this->ask($words, ['draft_id' => $draft->id, 'find_more_photos' => true])['ok']);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }
}
