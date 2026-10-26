<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Exceptions\UnreconciledDraftSpecificationsException;
use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\DeliverTelegramAssistantReply;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramAssistantReplyDelivery;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TelegramAssistantReplyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Sleep::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        $budget = $this->mock(ProductSearchTimeBudget::class);
        $budget->shouldNotReceive('restartSession');
        $budget->shouldReceive('canStart')->andReturn(true);
    }

    #[DataProvider('readyPartialDeliveryPaths')]
    public function test_sufficient_verified_partial_is_delivered_without_automatic_search(string $path): void
    {
        [$update, $draft] = $this->readyDraft(3);
        Category::where('slug', 'laptops')->update(['minimum_verified_images' => 3]);
        $draft->update(['gallery_search_stop_reason' => 'exhausted', 'gallery_confirmed_sufficient' => false]);
        $budget = $this->mock(ProductSearchCostBudget::class);
        $budget->shouldReceive('hasRemaining')->andReturn(true);
        $budget->shouldReceive('exceeded')->andReturn(false);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 92]]));
        $images = $this->mock(ProductImageStorage::class);
        $jobUpdate = $update;

        if ($path === 'manual callback') {
            $jobUpdate = TelegramUpdate::create(['update_id' => 910002, 'chat_id' => $update->chat_id,
                'telegram_user_id' => $update->telegram_user_id, 'payload' => [], 'status' => 'completed']);
            $images->shouldReceive('continueStage')->once()->andReturn(3);
        } elseif ($path === 'newly ready continuation') {
            $draft->update(['images_staged_at' => null]);
            $images->shouldReceive('continueStage')->once()->andReturnUsing(function (ProductDraft $current): int {
                $current->update(['images_staged_at' => now()]);

                return 3;
            });
        } else {
            $images->shouldNotReceive('continueStage');
        }

        if ($path === 'presenter') {
            $delivery = app(TelegramAssistantReplyDelivery::class);
            $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));
        } else {
            (new ContinueDraftGallerySearch($draft->id, $jobUpdate->chat_id, $jobUpdate->id, $update->id))
                ->handle($images, app(DraftTelegramPresenter::class), app(TelegramClient::class));
        }

        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMediaGroup'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains(json_encode($request->data()), "draft:add:{$draft->id}"));
        $this->assertSame('completed', $jobUpdate->fresh()->status);
        $this->assertNotNull($jobUpdate->fresh()->processed_at);
        $checkpoint = AiOperation::where('action', 'deliver_assistant_reply')->where('target_type', 'draft_reply')->sole();
        $this->assertSame('completed', $checkpoint->status);
        $this->assertSame('sent', $checkpoint->result['delivery_outcome']);
        $this->assertSame('partial', $draft->fresh()->gallery_status);
        $this->assertFalse($draft->fresh()->gallery_confirmed_sufficient);
        $this->assertTrue($draft->fresh()->hasReadyVerifiedPartial());
    }

    public static function readyPartialDeliveryPaths(): array
    {
        return [['presenter'], ['already queued automatic'], ['newly ready continuation'], ['manual callback']];
    }

    #[DataProvider('incompletePartialStates')]
    public function test_partial_readiness_requires_staging_reconciliation_and_readable_verified_minimum(string $state): void
    {
        [, $draft] = $this->readyDraft(3);
        Category::where('slug', 'laptops')->update(['minimum_verified_images' => 3]);
        if ($state === 'pending photo') {
            $draft->media()->first()->update(['verification_status' => 'pending']);
        } elseif ($state === 'missing file') {
            Storage::disk('public')->delete($draft->media()->first()->path);
        } elseif ($state === 'below minimum') {
            $draft->media()->first()->delete();
        } elseif ($state === 'unreconciled') {
            $draft->update(['specifications_reconciled_source_url' => null]);
        } elseif ($state === 'unstaged') {
            $draft->update(['images_staged_at' => null]);
        } else {
            $draft->update(['status' => 'approved']);
        }

        $this->assertFalse($draft->fresh()->hasReadyVerifiedPartial());
    }

    public static function incompletePartialStates(): array
    {
        return [['pending photo'], ['missing file'], ['below minimum'], ['unreconciled'], ['unstaged'], ['processed']];
    }

    #[DataProvider('partialPhotoCounts')]
    public function test_failed_ready_partial_delivery_retries_without_another_ai_call(int $photoCount): void
    {
        [$update, $draft] = $this->readyDraft($photoCount);
        $prompts = 0;
        ServerAssistantAgent::fake(function () use ($draft, &$prompts): array {
            $prompts++;

            return $this->response($draft);
        })->preventStrayPrompts();
        $available = false;
        Http::fake(function () use (&$available) {
            return $available ? Http::response(['ok' => true, 'result' => ['message_id' => 50]]) : Http::response(['ok' => false, 'description' => 'Temporary Telegram error'], 503);
        });

        $this->attemptJob($update);
        $available = true;
        $this->attemptJob($update, shouldFail: false);

        $this->assertSame(1, $prompts);
        $this->assertSame(1, AiRun::where('telegram_update_id', $update->id)->where('model', '!=', 'fixture')->count());
        $this->assertSame('completed', AiRun::where('telegram_update_id', $update->id)->latest('id')->firstOrFail()->status);
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSame($photoCount, $draft->media()->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), $photoCount === 1 ? '/sendPhoto' : '/sendMessage')
            && ($photoCount === 1 || str_contains((string) $request['text'], 'Delivery fixture laptop')));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === ($photoCount > 0 ? "draft:add:{$draft->id}" : "draft:continue-search:{$draft->id}"));
    }

    public static function partialPhotoCounts(): array
    {
        return [[0], [1]];
    }

    #[DataProvider('unreconciledPreviewCases')]
    public function test_stopped_search_delivers_verified_photos_without_offering_unreconciled_publication(int $photoCount, string $reason): void
    {
        [$update, $draft] = $this->readyDraft($photoCount);
        $draft->update(['specifications_reconciled_source_url' => null, 'gallery_search_stop_reason' => $reason]);
        $draft->media()->first()->update(['verification_status' => 'source_verified']);
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('hasRemaining')->andReturn(false);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));

        Http::assertSent(function (Request $request) use ($photoCount): bool {
            if (! str_ends_with($request->url(), $photoCount === 1 ? '/sendPhoto' : '/sendMediaGroup')) {
                return false;
            }
            $parts = collect($request->data())->pluck('contents', 'name');
            $caption = $photoCount === 1 ? (string) $parts->get('caption')
                : (string) data_get(json_decode((string) $parts->get('media'), true), '0.caption');

            return str_contains($caption, "Частичный результат: {$photoCount} фото")
                && str_contains($caption, 'точная комплектация и характеристики ещё не подтверждены')
                && ! str_contains($caption, 'Complete verified description.');
        });
        $this->assertStringContainsString('https://shop.example/exact', $draft->fresh()->telegram_review_caption);
        Http::assertSent(function (Request $request) use ($draft, $reason): bool {
            $buttons = collect(data_get($request->data(), 'reply_markup.inline_keyboard', []))->flatten(1)->pluck('callback_data')->all();

            return in_array("draft:continue-search:{$draft->id}", $buttons, true) === in_array($reason, ['cost_budget', 'time_budget'], true)
                && in_array("draft:reject:{$draft->id}", $buttons, true)
                && ! in_array("draft:add:{$draft->id}", $buttons, true);
        });
        $this->assertTrue($draft->fresh()->reconciliationPending());
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSame('pending_review', $draft->fresh()->status);
        Queue::assertNothingPushed();
        $this->expectException(UnreconciledDraftSpecificationsException::class);
        app(ProductDraftWorkflow::class)->approve($draft);
    }

    public static function unreconciledPreviewCases(): array
    {
        return [[1, 'cost_budget'], [3, 'cost_budget'], [1, 'time_budget'], [3, 'specifications_unreconciled']];
    }

    public function test_partial_preview_omits_pending_and_missing_files(): void
    {
        [$update, $draft] = $this->readyDraft(3);
        $draft->update(['specifications_reconciled_source_url' => null]);
        $media = $draft->media()->orderBy('id')->get();
        $media[1]->update(['verification_status' => 'pending']);
        Storage::disk('public')->delete($media[2]->path);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 72]]));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMediaGroup'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) collect($request->data())->pluck('contents', 'name')->get('caption'), 'Частичный результат: 1 фото'));
        $this->assertSame(3, $draft->media()->count());
    }

    public function test_unverified_saved_files_are_not_sent_as_a_partial_photo_result(): void
    {
        [$update, $draft] = $this->readyDraft(1);
        $draft->update(['specifications_reconciled_source_url' => null]);
        $draft->media()->update(['verification_status' => 'pending']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 73]]));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            || str_ends_with($request->url(), '/sendMediaGroup'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), "Поиск #{$draft->id} не завершён"));
    }

    public function test_partial_preview_waits_for_automatic_continuation_while_budget_remains(): void
    {
        [$update, $draft] = $this->readyDraft(1);
        $draft->update(['specifications_reconciled_source_url' => null, 'gallery_search_stop_reason' => 'time_budget']);
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('hasRemaining')->andReturn(true);
        Http::fake();
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));

        Http::assertNothingSent();
        Queue::assertPushed(ContinueDraftGallerySearch::class);
        $this->assertSame('processing', $update->fresh()->status);
    }

    public function test_partial_preview_receipt_survives_failed_controls_and_retry(): void
    {
        [$update, $draft] = $this->readyDraft(3);
        $draft->update(['specifications_reconciled_source_url' => null]);
        $controlsAvailable = false;
        $albumCalls = 0;
        Http::fake(function (Request $request) use (&$controlsAvailable, &$albumCalls) {
            if (str_ends_with($request->url(), '/sendMediaGroup')) {
                $albumCalls++;

                return Http::response(['ok' => true, 'result' => [['message_id' => 74], ['message_id' => 75], ['message_id' => 76]]]);
            }

            return $controlsAvailable ? Http::response(['ok' => true, 'result' => ['message_id' => 77]]) : Http::response(['ok' => false], 503);
        });
        $delivery = app(TelegramAssistantReplyDelivery::class);
        $checkpoint = $delivery->prepareDraft($update, $draft);
        try {
            $delivery->deliver($checkpoint, app(TelegramClient::class));
            $this->fail('Expected control message delivery failure.');
        } catch (RuntimeException) {
        }
        $controlsAvailable = true;

        $delivery->deliver($checkpoint, app(TelegramClient::class));

        $this->assertSame(1, $albumCalls);
        $this->assertSame('completed', $checkpoint->fresh()->status);
        $this->assertSame([74, 75, 76], $draft->fresh()->telegram_review_message_ids);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessages'));
    }

    #[DataProvider('stalePreviewStates')]
    public function test_partial_preview_delivery_cannot_resurrect_cancelled_finalized_or_replaced_drafts(string $state): void
    {
        [$update, $draft] = $this->readyDraft(1);
        $draft->update(['specifications_reconciled_source_url' => null]);
        $delivery = app(TelegramAssistantReplyDelivery::class);
        $checkpoint = $delivery->prepareDraft($update, $draft);
        if ($state === 'cancelled') {
            $update->update(['status' => 'cancelled', 'cancel_requested_at' => now()]);
        } elseif ($state === 'generation') {
            $replacement = TelegramUpdate::create(['update_id' => 910002, 'chat_id' => $update->chat_id,
                'telegram_user_id' => $update->telegram_user_id, 'payload' => [], 'status' => 'completed']);
            $draft->update(['telegram_update_id' => $replacement->id]);
        } else {
            $draft->update(['status' => $state]);
        }
        Http::fake();

        $delivery->deliver($checkpoint, app(TelegramClient::class));

        Http::assertNothingSent();
        $this->assertSame($state === 'cancelled' ? 'cancelled' : 'skipped_stale',
            $state === 'cancelled' ? $checkpoint->fresh()->status : data_get($checkpoint->fresh()->result, 'delivery_outcome'));
    }

    public static function stalePreviewStates(): array
    {
        return [['cancelled'], ['generation'], ['approved'], ['rejected']];
    }

    public function test_delivery_retry_uses_an_async_driver_even_when_sync_connection_has_another_name(): void
    {
        config(['queue.default' => 'renamed', 'queue.connections.renamed.driver' => 'sync']);

        $job = new DeliverTelegramAssistantReply(123);

        $this->assertSame('database', config('queue.connections.'.$job->connection.'.driver'));
    }

    public function test_cancellation_after_album_blocks_controls_without_scheduling_retry(): void
    {
        [$update, $draft] = $this->readyDraft(3);
        ServerAssistantAgent::fake([$this->response($draft)])->preventStrayPrompts();
        Http::fake(function (Request $request) use ($update) {
            if (str_ends_with($request->url(), '/sendMediaGroup')) {
                $update->update(['status' => 'cancelled', 'cancel_requested_at' => now()]);

                return Http::response(['ok' => true, 'result' => [['message_id' => 50], ['message_id' => 51], ['message_id' => 52]]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 53]]);
        });

        $this->attemptJob($update, shouldFail: false);

        $this->assertSame('cancelled', $update->fresh()->status);
        $this->assertSame('cancelled', AiOperation::where('action', 'deliver_assistant_reply')->sole()->status);
        Queue::assertNotPushed(DeliverTelegramAssistantReply::class);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_replayed_successful_album_keeps_already_delivered_controls(): void
    {
        [$update, $draft] = $this->readyDraft(3);
        $prompts = 0;
        ServerAssistantAgent::fake(function () use ($draft, &$prompts): array {
            $prompts++;

            return $this->response($draft);
        })->preventStrayPrompts();
        $albumCalls = 0;
        Http::fake(function (Request $request) use (&$albumCalls) {
            if (str_ends_with($request->url(), '/sendMediaGroup')) {
                $albumCalls++;

                return Http::response(['ok' => true, 'result' => [['message_id' => 50], ['message_id' => 51], ['message_id' => 52]]]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 53]]);
        });
        $this->attemptJob($update, shouldFail: false);
        $checkpoint = AiOperation::where('action', 'deliver_assistant_reply')->sole();
        $controls = $draft->fresh()->telegram_control_message_ids;
        $this->assertNotEmpty($controls);
        // Simulate a crash after Telegram and lifecycle succeeded, before checkpoint completion.
        $checkpoint->update(['status' => 'failed']);

        $this->attemptJob($update, shouldFail: false);

        $this->assertSame(1, $prompts);
        $this->assertSame(1, $albumCalls);
        $this->assertSame($controls, $draft->fresh()->telegram_control_message_ids);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessages'));
    }

    public function test_album_is_not_resent_when_only_approval_controls_failed(): void
    {
        [$update, $draft] = $this->readyDraft(3);
        $prompts = 0;
        ServerAssistantAgent::fake(function () use ($draft, &$prompts): array {
            $prompts++;

            return $this->response($draft);
        })->preventStrayPrompts();
        $controlsAvailable = false;
        $albumCalls = 0;
        Http::fake(function (Request $request) use (&$controlsAvailable, &$albumCalls) {
            if (str_ends_with($request->url(), '/sendMediaGroup')) {
                $albumCalls++;

                return Http::response(['ok' => true, 'result' => [['message_id' => 50], ['message_id' => 51], ['message_id' => 52]]]);
            }

            return $controlsAvailable ? Http::response(['ok' => true, 'result' => ['message_id' => 53]]) : Http::response(['ok' => false], 503);
        });

        $this->attemptJob($update);
        $controlsAvailable = true;
        $this->attemptJob($update, shouldFail: false);

        $this->assertSame(1, $prompts);
        $this->assertSame(1, $albumCalls);
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertNotEmpty($draft->fresh()->telegram_control_message_ids);
    }

    public function test_cancellation_between_delivery_attempts_does_not_resurrect_the_card(): void
    {
        [$update, $draft] = $this->readyDraft(1);
        $prompts = 0;
        ServerAssistantAgent::fake(function () use ($draft, &$prompts): array {
            $prompts++;

            return $this->response($draft);
        })->preventStrayPrompts();
        Http::fake(fn () => Http::response(['ok' => false], 503));
        $this->attemptJob($update);
        $update->update(['status' => 'cancelled', 'cancel_requested_at' => now()]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 54]]));

        $this->attemptJob($update, shouldFail: false);

        $this->assertSame(1, $prompts);
        $this->assertSame('cancelled', $update->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame('pending_review', $draft->fresh()->status);
    }

    #[DataProvider('partialPhotoCounts')]
    public function test_continuation_final_result_has_independent_delivery_retry_without_repeating_search(int $photoCount): void
    {
        [$update, $draft] = $this->readyDraft($photoCount);
        $draft->update(['images_staged_at' => null]);
        ServerAssistantAgent::fake([])->preventStrayPrompts();
        $images = $this->mock(ProductImageStorage::class);
        $images->shouldReceive('continueStage')->once()->andReturnUsing(function (ProductDraft $current) use ($photoCount): int {
            $current->update(['images_staged_at' => now()]);

            return $photoCount;
        });
        $available = false;
        Http::fake(function (Request $request) use (&$available) {
            $isFinal = str_ends_with($request->url(), '/sendPhoto')
                || (str_ends_with($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'Delivery fixture laptop'));

            return $isFinal && ! $available ? Http::response(['ok' => false], 503)
                : Http::response(['ok' => true, 'result' => ['message_id' => 90]]);
        });
        try {
            (new ContinueDraftGallerySearch($draft->id, $update->chat_id, $update->id, $update->id))
                ->handle($images, app(DraftTelegramPresenter::class), app(TelegramClient::class));
            $this->fail('Expected final Telegram send failure.');
        } catch (RuntimeException) {
        }
        $checkpoint = AiOperation::where('action', 'deliver_assistant_reply')->where('target_type', 'draft_reply')->sole();
        $this->assertNull($update->fresh()->processed_at);
        Queue::assertPushed(DeliverTelegramAssistantReply::class, fn ($job): bool => $job->checkpointId === $checkpoint->id);

        $available = true;
        (new DeliverTelegramAssistantReply($checkpoint->id))->handle(app(TelegramAssistantReplyDelivery::class), app(TelegramClient::class));

        $this->assertSame('completed', $checkpoint->fresh()->status);
        $this->assertSame('completed', $update->fresh()->status);
        $this->assertSame(1, AiRun::where('telegram_update_id', $update->id)->count());
        $this->assertSame($photoCount, $draft->media()->count());
        ServerAssistantAgent::assertNeverPrompted();
    }

    private function attemptJob(TelegramUpdate $update, bool $shouldFail = true): void
    {
        try {
            (new ProcessTelegramMessage($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
            $this->assertFalse($shouldFail, 'Expected a failed Telegram delivery.');
        } catch (RuntimeException $exception) {
            if (! $shouldFail) {
                throw $exception;
            }
        }
    }

    private function response(ProductDraft $draft): array
    {
        return ['response_type' => 'research_result', 'message' => 'Ready.', 'draft_id' => $draft->id, 'product_ids' => [], 'operation_ids' => []];
    }

    /** @return array{TelegramUpdate, ProductDraft} */
    public function test_the_partial_result_names_the_operators_request_and_the_found_product_separately(): void
    {
        // Live #206: the operator asked for 14-fm0001nn by its label, the closest
        // observed sibling was 14-fm0001na, and the caption read "Запрос: ... 14-fm0001na".
        [$update, $draft] = $this->readyDraft(1);
        $update->update(['text' => "HP OmniBook X Flip 14-fm0001nn\nP/N: B2VK4EA#BED"]);
        $draft->update(['title' => 'HP OmniBook X Flip 14-fm0001na 14-inch Convertible Laptop',
            'specifications_reconciled_source_url' => null, 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        $draft->media()->first()->update(['verification_status' => 'source_verified']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update, $draft), app(TelegramClient::class));

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/sendPhoto')) {
                return false;
            }
            $caption = (string) collect($request->data())->pluck('contents', 'name')->get('caption');

            return str_contains($caption, "Запрос: HP OmniBook X Flip 14-fm0001nn P/N: B2VK4EA#BED\nНайдено: HP OmniBook X Flip 14-fm0001na 14-inch Convertible Laptop");
        });
    }

    private function readyDraft(int $photoCount): array
    {
        $update = TelegramUpdate::create(['update_id' => 910001, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => 'Find fixture laptop', 'payload' => [], 'status' => 'received']);
        $run = $update->aiRuns()->create(['provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Delivery fixture laptop', 'description' => 'Complete verified description.',
            'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop', 'condition' => 'new',
            'specifications' => [], 'sources' => [['url' => 'https://shop.example/exact', 'title' => 'Exact source']], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/exact', 'specifications_reconciled_source_url' => 'https://shop.example/exact',
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'cost_budget', 'images_staged_at' => now()]);
        foreach (range(1, $photoCount ?: 1) as $index) {
            if ($photoCount === 0) {
                break;
            }
            $path = "drafts/{$draft->id}/{$index}.jpg";
            Storage::disk('public')->put($path, 'fake-image-'.$index);
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'fake-image-'.$index),
                'width' => 1000, 'height' => 800, 'verification_status' => 'verified', 'is_primary' => $index === 1, 'sort_order' => $index]);
        }

        return [$update, $draft];
    }
}
