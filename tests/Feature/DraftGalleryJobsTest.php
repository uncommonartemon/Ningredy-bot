<?php

namespace Tests\Feature;

use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class DraftGalleryJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_restage_job_blacklists_current_gallery_before_searching_again(): void
    {
        $draft = $this->draft();
        Cache::put("draft-gallery-restage:{$draft->id}:queued", true, 600);
        $images = $this->mock(ProductImageStorage::class, function (MockInterface $mock) use ($draft): void {
            $mock->shouldReceive('excludeCurrentDraftGallery')
                ->once()
                ->withArgs(fn (ProductDraft $argument): bool => $argument->is($draft));
            $mock->shouldReceive('stage')
                ->once()
                ->andReturn(0);
        });
        $presenter = $this->mock(DraftTelegramPresenter::class);
        $presenter->shouldReceive('sendReview')->once();
        $telegram = $this->telegramMock();

        (new RestageDraftGalleryPhotos($draft->id, '100', $draft->telegram_update_id))->handle($images, $presenter, $telegram);

        $this->assertFalse(Cache::has("draft-gallery-restage:{$draft->id}:queued"));
    }

    public function test_top_up_job_appends_photos_without_replacing_the_gallery(): void
    {
        $draft = $this->draft();
        Cache::put("draft-gallery-topup:{$draft->id}:queued", true, 600);
        $images = $this->mock(ProductImageStorage::class);
        $images->shouldReceive('topUpDraftMedia')
            ->once()
            ->andReturn(2);
        $images->shouldNotReceive('stage');
        $presenter = $this->mock(DraftTelegramPresenter::class);
        $presenter->shouldReceive('sendReview')->once();
        $telegram = $this->telegramMock();

        (new TopUpDraftGalleryPhotos($draft->id, '100', $draft->telegram_update_id))->handle($images, $presenter, $telegram);

        $this->assertFalse(Cache::has("draft-gallery-topup:{$draft->id}:queued"));
    }

    private function draft(): ProductDraft
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

        return ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'status' => 'pending_review',
            'requested_by_telegram_user_id' => '1',
            'title' => 'Test product',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
            'confidence' => 1,
        ]);
    }

    private function telegramMock(): TelegramClient
    {
        return $this->mock(TelegramClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')
                ->atLeast()
                ->once()
                ->andReturn(['result' => ['message_id' => 10]]);
            $mock->shouldReceive('editMessageText')
                ->zeroOrMoreTimes()
                ->andReturn(['ok' => true]);
        });
    }
}
