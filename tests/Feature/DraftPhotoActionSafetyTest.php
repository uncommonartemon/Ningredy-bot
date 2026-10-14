<?php

namespace Tests\Feature;

use App\Ai\Tools\ManageDraftPhotos;
use App\Jobs\ProcessDraftPhotoActions;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\DraftPhotoManager;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoEnhancer;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DraftPhotoActionSafetyTest extends TestCase
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
        Http::preventStrayRequests();
    }

    public function test_successful_redelivery_and_failure_hook_do_not_repeat_or_downgrade_an_operation(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $enhancer = Mockery::mock(ProductPhotoEnhancer::class);
        $enhancer->shouldReceive('enhance')->once()->andReturn(['ok' => true]);
        $job = $this->job($draft, $update, $media, $operation->id);
        $this->runJob($job, $enhancer);
        Cache::put("draft-photo-actions:{$draft->id}:queued", 999999, 600);
        $this->runJob($this->job($draft, $update, $media, $operation->id), $enhancer);
        $job->failed(new RuntimeException('Late queue acknowledgement failure'));

        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame([0], $operation->fresh()->result['completed_actions']);
        $this->assertSame(999999, Cache::get("draft-photo-actions:{$draft->id}:queued"));
    }

    public function test_legacy_dispatch_without_operation_id_has_a_durable_retry_receipt(): void
    {
        [$draft, $update, $media] = $this->fixtures();
        $enhancer = Mockery::mock(ProductPhotoEnhancer::class);
        $enhancer->shouldReceive('enhance')->once()->andReturn(['ok' => true]);
        $this->runJob($this->job($draft, $update, $media), $enhancer);
        $this->runJob($this->job($draft, $update, $media), $enhancer);

        $this->assertSame(1, AiOperation::where('action', 'process_draft_photos')->where('status', 'completed')->count());
    }

    public function test_retry_after_second_photo_failure_preserves_the_successful_first_action(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $second = $draft->media()->create(['disk' => 'public', 'path' => 'drafts/second.jpg', 'checksum' => 'second']);
        $job = $this->job($draft, $update, $media, $operation->id);
        $job->actions[] = ['action' => 'replace', 'media_id' => $second->id, 'position' => 2];
        $enhancer = Mockery::mock(ProductPhotoEnhancer::class);
        $enhancer->shouldReceive('enhance')->once()->andReturn(['ok' => true]);
        $images = Mockery::mock(ProductImageStorage::class);
        $attempts = 0;
        $images->shouldReceive('replaceDraftMedia')->twice()->andReturnUsing(function () use (&$attempts, $second) {
            if (++$attempts === 1) {
                throw new RuntimeException('Temporary provider failure');
            }

            return $second;
        });
        try {
            $this->runJob($job, $enhancer, $images);
            $this->fail('The simulated error did not occur.');
        } catch (RuntimeException $error) {
            $this->assertSame('Temporary provider failure', $error->getMessage());
        }
        $this->assertSame([0], $operation->fresh()->result['completed_actions']);
        $this->runJob($job, $enhancer, $images);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame([0, 1], $operation->fresh()->result['completed_actions']);
    }

    public function test_cancelled_job_never_calls_image_services(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $update->update(['cancel_requested_at' => now()]);
        $this->runJob($this->job($draft, $update, $media, $operation->id), Mockery::mock(ProductPhotoEnhancer::class));

        $this->assertSame('cancelled', $operation->fresh()->status);
        $this->assertFalse(Cache::has("draft-photo-actions:{$draft->id}:queued"));
        Http::assertNothingSent();
    }

    public function test_cancellation_during_provider_call_prevents_persistence_and_remaining_actions(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $job = $this->job($draft, $update, $media, $operation->id);
        $job->actions[] = ['action' => 'delete', 'media_id' => $media->id, 'position' => 1];
        $enhancer = Mockery::mock(ProductPhotoEnhancer::class);
        $enhancer->shouldReceive('enhance')->once()->andReturnUsing(function ($frame, $updateId, $target, $beforeSave) use ($update) {
            $update->update(['cancel_requested_at' => now()]);
            $beforeSave();
            $this->fail('Cancelled result reached persistence.');
        });
        $this->runJob($job, $enhancer);

        $this->assertSame('cancelled', $operation->fresh()->status);
        $this->assertDatabaseHas('product_draft_media', ['id' => $media->id, 'checksum' => 'original']);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_a_different_operator_or_generation_cannot_execute_a_queued_photo_change(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $draft->update(['requested_by_telegram_user_id' => 'another-admin']);
        $this->runJob($this->job($draft, $update, $media, $operation->id), Mockery::mock(ProductPhotoEnhancer::class));
        $this->assertSame('ignored', $operation->fresh()->status);

        $draft->update(['requested_by_telegram_user_id' => $update->telegram_user_id]);
        $job = $this->job($draft, $update, $media);
        $job->expectedDraftTelegramUpdateId = $draft->telegram_update_id + 100;
        $this->runJob($job, Mockery::mock(ProductPhotoEnhancer::class));
        $this->assertSame('ignored', AiOperation::find($job->operationId)->status);
        Http::assertNothingSent();
    }

    public function test_repeating_the_ai_tool_returns_its_receipt_without_requeue_or_busy_lock(): void
    {
        [$draft, $update, $media] = $this->fixtures();
        Cache::forget("draft-photo-actions:{$draft->id}:queued");
        $tool = new ManageDraftPhotos($update);
        $request = new Request(['draft_id' => $draft->id, 'enhance_positions' => [1]]);
        $first = json_decode($tool->handle($request), true);
        $second = json_decode($tool->handle($request), true);

        $this->assertSame($first, $second);
        Queue::assertPushed(ProcessDraftPhotoActions::class, 1);
        $this->assertSame($update->id, Cache::get("draft-photo-actions:{$draft->id}:queued"));
    }

    public function test_photo_callback_can_be_cancelled_after_its_webhook_was_acknowledged(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        config(['services.telegram.webhook_secret' => 'test-secret', 'services.telegram.allowed_user_ids' => ['12345']]);
        $this->postJson('/api/telegram/webhook', ['update_id' => 991003, 'callback_query' => [
            'id' => 'cancel-photo', 'from' => ['id' => 12345], 'data' => "search:cancel:{$update->id}",
            'message' => ['message_id' => 100, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertNotNull($update->fresh()->cancel_requested_at);
        $this->runJob($this->job($draft, $update, $media, $operation->id), Mockery::mock(ProductPhotoEnhancer::class));
        $this->assertSame('cancelled', $operation->fresh()->status);
    }

    public function test_actual_enhancer_discards_a_cancelled_provider_response_before_writing_the_file(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        Image::fake(function () use ($update) {
            $update->update(['cancel_requested_at' => now()]);

            return base64_encode('not persisted');
        })->preventStrayImages();
        $this->runJob($this->job($draft, $update, $media, $operation->id), app(ProductPhotoEnhancer::class));

        $this->assertSame('original', Storage::disk('public')->get($media->path));
        $this->assertSame('cancelled', $operation->fresh()->status);
        $run = AiRun::where('telegram_update_id', $update->id)->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertIsArray($run->usage);
    }

    public function test_telegram_delivery_failure_after_success_does_not_repay_on_retry(): void
    {
        [$draft, $update, $media, $operation] = $this->fixtures();
        $enhancer = Mockery::mock(ProductPhotoEnhancer::class);
        $enhancer->shouldReceive('enhance')->once()->andReturn(['ok' => true]);
        $presenter = Mockery::mock(DraftTelegramPresenter::class);
        $presenter->shouldReceive('sendReview')->once()->andThrow(new RuntimeException('Telegram delivery failed'));
        $presenter->shouldReceive('sendControls')->once();
        $job = $this->job($draft, $update, $media, $operation->id);
        try {
            $job->handle($enhancer, Mockery::mock(ProductImageStorage::class), Mockery::mock(DraftPhotoManager::class),
                $presenter, app(TelegramClient::class));
            $this->fail('The delivery fault was not simulated.');
        } catch (RuntimeException $error) {
            $this->assertSame('Telegram delivery failed', $error->getMessage());
        }
        $this->runJob($this->job($draft, $update, $media, $operation->id), $enhancer);
        $this->assertSame('completed', $operation->fresh()->status);
    }

    private function runJob(ProcessDraftPhotoActions $job, ProductPhotoEnhancer $enhancer, ?ProductImageStorage $images = null): void
    {
        $presenter = Mockery::mock(DraftTelegramPresenter::class);
        $presenter->shouldReceive('sendReview')->zeroOrMoreTimes();
        $presenter->shouldReceive('sendControls')->zeroOrMoreTimes();
        $job->handle($enhancer, $images ?? Mockery::mock(ProductImageStorage::class),
            Mockery::mock(DraftPhotoManager::class), $presenter, app(TelegramClient::class));
    }

    private function job($draft, $update, $media, ?int $operationId = null): ProcessDraftPhotoActions
    {
        return new ProcessDraftPhotoActions($draft->id, [['action' => 'enhance', 'media_id' => $media->id, 'position' => 1]],
            $update->id, $update->chat_id, $operationId, $draft->telegram_update_id);
    }

    private function fixtures(): array
    {
        $origin = TelegramUpdate::create(['update_id' => 991001, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
        $update = TelegramUpdate::create(['update_id' => 991002, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
        $run = AiRun::create(['telegram_update_id' => $origin->id, 'provider' => 'local', 'model' => 'fixture',
            'status' => 'completed', 'prompt' => 'No provider call', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $origin->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => $origin->telegram_user_id, 'title' => 'Sample laptop', 'product_type' => 'laptop',
            'category' => 'laptops', 'status' => 'pending_review', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        $media = $draft->media()->create(['disk' => 'public', 'path' => 'drafts/original.jpg', 'checksum' => 'original', 'is_primary' => true]);
        Storage::disk('public')->put($media->path, 'original');
        $operation = AiOperation::create(['telegram_update_id' => $update->id, 'telegram_user_id' => $update->telegram_user_id,
            'tool' => 'TelegramCallback', 'action' => 'enhance_draft_photo', 'target_type' => $media::class, 'target_id' => $media->id,
            'idempotency_key' => 'fixture-operation', 'payload' => ['draft_id' => $draft->id, 'media_id' => $media->id], 'status' => 'running']);
        Cache::put("draft-photo-actions:{$draft->id}:queued", $update->id, 600);

        return [$draft, $update, $media, $operation];
    }
}
