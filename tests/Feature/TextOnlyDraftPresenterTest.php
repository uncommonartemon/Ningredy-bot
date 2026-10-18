<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TextOnlyDraftPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'test-token']);
        $messageId = 900;
        Http::fake(['https://api.telegram.org/*' => function () use (&$messageId) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$messageId]]);
        }]);
    }

    private function draft(string $reason, bool $assembled = true, bool $staged = true): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => 345678, 'telegram_user_id' => '123', 'chat_id' => '456',
            'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture',
            'status' => 'completed', 'prompt' => 'fixture', 'started_at' => now()]);
        $source = 'https://shop.example/exact-laptop';

        return ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'status' => 'pending_review', 'requested_by_telegram_user_id' => '123', 'title' => 'Verified laptop', 'description' => 'Detailed verified description.',
            'primary_source_url' => $source, 'specifications_reconciled_source_url' => $assembled ? $source : null,
            'specifications' => collect(range(1, 24))->map(fn ($index): array => ['key' => 'fact_'.$index, 'name' => 'Fact '.$index, 'value' => 'Value '.$index])->all(),
            'sources' => [['url' => $source]], 'image_urls' => [], 'gallery_status' => 'missing',
            'gallery_search_stop_reason' => $reason, 'images_staged_at' => $staged ? now() : null]);
    }

    private function budget(bool $remaining): void
    {
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('hasRemaining')->andReturn($remaining);
    }

    public static function terminalStops(): array
    {
        return [['cost_budget', 'Денежный лимит поиска достигнут.'], ['time_budget', 'Время поиска закончилось.']];
    }

    #[DataProvider('terminalStops')]
    public function test_completed_zero_photo_card_has_a_clear_caption_and_full_details_without_approval(string $reason, string $notice): void
    {
        $draft = $this->draft($reason);
        $this->budget(false);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '456', $draft);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Информация собрана; фото не найдены.')
            && str_contains((string) $request['text'], $notice)
            && ! str_contains((string) $request['text'], 'готов к добавлению')
            && ! str_contains((string) $request['text'], 'поиск фотографий приостановлен'));
        $controls = Http::recorded()->flatMap(fn ($pair): array => (array) ($pair[0]['reply_markup']['inline_keyboard'] ?? []))
            ->flatten(1)->pluck('callback_data')->filter()->all();
        $this->assertContains("draft:continue-search:{$draft->id}", $controls);
        $this->assertContains("draft:details:{$draft->id}", $controls);
        $this->assertNotContains("draft:add:{$draft->id}", $controls);
        Queue::assertNothingPushed();
        $this->assertFalse($draft->fresh()->telegram_review_has_media);
        $this->assertFalse($draft->fresh()->reconciliationPending());
    }

    public function test_unassembled_zero_photo_information_is_not_presented_as_a_ready_text_card(): void
    {
        $draft = $this->draft('cost_budget', assembled: false);
        $this->budget(false);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '456', $draft);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Поиск #'.$draft->id.' не завершён'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Информация собрана; фото не найдены.')
            || str_contains(json_encode($request->data()), "draft:add:{$draft->id}"));
        $this->assertTrue($draft->fresh()->reconciliationPending());
    }

    public function test_interrupted_staging_does_not_claim_a_completed_zero_photo_result(): void
    {
        $draft = $this->draft('cost_budget', staged: false);
        $this->budget(false);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '456', $draft);
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'поиск фотографий приостановлен'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Информация собрана; фото не найдены.'));
    }

    public function test_exhausted_cycle_with_a_live_budget_and_cache_marker_does_not_send_or_queue_a_duplicate(): void
    {
        $draft = $this->draft('exhausted');
        $this->budget(true);
        $draft->telegramUpdate->update(['status' => 'delivering', 'processed_at' => null]);
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, 2100);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '456', $draft);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame('processing', $draft->telegramUpdate->fresh()->status);
    }

    public function test_exhausted_cycle_already_queued_by_research_stays_silent_and_native_unique_job_prevents_duplication(): void
    {
        $draft = $this->draft('exhausted');
        $this->budget(true);
        ContinueDraftGallerySearch::dispatch($draft->id, '456', $draft->telegram_update_id, $draft->telegram_update_id)->afterCommit();
        $presenter = app(DraftTelegramPresenter::class);
        $presenter->sendReview(app(TelegramClient::class), '456', $draft);
        $presenter->sendReview(app(TelegramClient::class), '456', $draft);
        Http::assertNothingSent();
        Queue::assertPushed(ContinueDraftGallerySearch::class, 1);
        $this->assertTrue(Cache::has("draft-gallery-continue:{$draft->id}:queued"));
        $this->assertSame('processing', $draft->telegramUpdate->fresh()->status);
    }
}
