<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\ProductSearch\Recovery\SearchFailureSaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SearchFailureSaverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        AppSetting::put('ai.max_search_cost_usd', '1');
        $budget = $this->mock(ProductSearchCostBudget::class);
        $budget->shouldReceive('unmeasurable')->andReturn(false);
        $budget->shouldReceive('hasRemaining')->andReturn(true);
        $budget->shouldReceive('exceeded')->andReturn(false);
        $budget->shouldReceive('spent')->andReturn(0.1);
        $budget->shouldReceive('limit')->andReturn(1.0);
    }

    public function test_a_crash_after_the_draft_was_saved_resumes_once_and_then_delivers_the_draft(): void
    {
        [$update, $draft] = $this->failedSearch();

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker lost mid-search'));

        Queue::assertPushed(ContinueDraftGallerySearch::class, fn (ContinueDraftGallerySearch $job): bool => $job->draftId === $draft->id);
        $this->assertSentText('Продолжаю с места остановки');
        $this->assertNotSentText('Не удалось выполнить запрос');
        $this->assertDatabaseHas('product_source_attempts', ['product_draft_id' => $draft->id,
            'phase' => 'delivery_guard', 'action' => 'resume_after_failure']);

        // The same request failing again is not resumed a second time: the operator gets the draft.
        Cache::flush();
        $update->update(['processed_at' => null]);
        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker lost again'));

        Queue::assertPushed(ContinueDraftGallerySearch::class, 1);
        $this->assertSentText('отправляю черновик');
        $this->assertDatabaseHas('product_source_attempts', ['product_draft_id' => $draft->id,
            'phase' => 'delivery_guard', 'action' => 'deliver_saved_after_failure']);
    }

    public function test_a_finished_draft_is_shown_instead_of_being_searched_again(): void
    {
        // Live #588: the card was assembled with ten photos when the reply failed.
        [$update, $draft] = $this->failedSearch();
        $draft->update(['images_staged_at' => now(), 'specifications_reconciled_source_url' => $draft->primary_source_url]);

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker lost after the card was ready'));

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        $this->assertSentText('отправляю черновик');
    }

    public function test_a_configuration_failure_is_not_resumed_but_the_saved_draft_is_still_delivered(): void
    {
        [$update] = $this->failedSearch();

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('insufficient_quota: You exceeded your current quota'));

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        $this->assertSentText('закончилась квота');
        $this->assertSentText('отправляю черновик');
    }

    public function test_a_continuation_already_queued_for_the_draft_owns_it_and_no_second_card_is_sent(): void
    {
        [$update, $draft] = $this->failedSearch();
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, 2100);
        DB::table('jobs')->insert(['queue' => 'media', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => json_encode(['displayName' => ContinueDraftGallerySearch::class, 'data' => ['commandName' => ContinueDraftGallerySearch::class,
                'command' => serialize(new ContinueDraftGallerySearch($draft->id, '98765', $update->id, $update->id))]])]);

        $this->assertSame('resumed', app(SearchFailureSaver::class)->save($update, ['kind' => 'worker_stopped']));

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        Http::assertNothingSent();
    }

    public function test_a_stale_continuation_mark_without_its_job_does_not_stop_the_resume(): void
    {
        [$update, $draft] = $this->failedSearch();
        Cache::put("draft-gallery-continue:{$draft->id}:queued", true, 2100);

        $this->assertSame('resumed', app(SearchFailureSaver::class)->save($update, ['kind' => 'worker_stopped']));

        Queue::assertPushed(ContinueDraftGallerySearch::class, fn (ContinueDraftGallerySearch $job): bool => $job->draftId === $draft->id);
        $this->assertSentText('Обработчик остановился во время поиска. Найденное сохранено: фото — 0.');
    }

    public function test_without_a_saved_draft_the_operator_gets_the_ordinary_error(): void
    {
        $update = $this->update();
        AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-6-luna',
            'status' => 'running', 'prompt' => 'x', 'started_at' => now()]);

        (new ProcessTelegramMessage($update->id))->failed(new RuntimeException('Worker lost mid-search'));

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        $this->assertSentText('Не удалось выполнить запрос');
    }

    /** @return array{TelegramUpdate, ProductDraft} */
    private function failedSearch(): array
    {
        $update = $this->update();
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-6-luna',
            'status' => 'running', 'prompt' => 'x', 'started_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'status' => 'pending_review', 'title' => 'Samsung Galaxy Tab S10 FE',
            'brand' => 'Samsung', 'model' => 'SM-X520NZAREUE', 'product_type' => 'tablet',
            'primary_source_url' => 'https://shop.example/tab-s10-fe', 'specifications' => [], 'sources' => [], 'image_urls' => []]);

        return [$update, $draft];
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => random_int(2000, 9000), 'telegram_user_id' => '12345',
            'chat_id' => '98765', 'message_id' => 55, 'text' => 'Samsung Galaxy Tab S10 FE', 'payload' => [], 'status' => 'processing']);
    }

    private function assertSentText(string $needle): void
    {
        Http::assertSent(fn (HttpRequest $request): bool => str_contains((string) ($request['text'] ?? $request['caption'] ?? ''), $needle));
    }

    private function assertNotSentText(string $needle): void
    {
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains((string) ($request['text'] ?? $request['caption'] ?? ''), $needle));
    }
}
