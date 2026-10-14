<?php

namespace Tests\Feature;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Ai\Tools\RetryFailedJob;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\StoreProductImages;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\AiOperation;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OperationsSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => 654321, 'telegram_user_id' => '111',
            'chat_id' => '111', 'payload' => [], 'status' => 'processing']);
    }

    public function test_startup_preserves_catalog_writes_and_unknown_jobs_but_cancels_search(): void
    {
        config(['queue.default' => 'database']);
        $queue = Queue::connection('database');
        $update = $this->update();
        $mediaId = $queue->push(new StoreProductImages(1, 2, 3));
        $queue->push(new ProcessTelegramMessage($update->id));
        $unknownId = DB::table('jobs')->insertGetId(['queue' => 'other', 'payload' => '{}',
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);

        $this->artisan('bot:clear-stale')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseHas('jobs', ['id' => $mediaId]);
        $this->assertDatabaseHas('jobs', ['id' => $unknownId]);
        $this->assertSame('cancelled', $update->fresh()->status);
    }

    public function test_business_failure_rolls_back_changes_but_keeps_failure_audit(): void
    {
        $update = $this->update();
        $runner = new class
        {
            use RecordsOperations;

            public function execute(TelegramUpdate $update): void
            {
                $this->recordOperation($update, 'Test', 'edit', [], function () use ($update): void {
                    $update->update(['text' => 'must roll back']);
                    throw new RuntimeException('specific failure');
                });
            }
        };
        try {
            $runner->execute($update);
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('specific failure', $exception->getMessage());
        }
        $this->assertNull($update->fresh()->text);
        $this->assertSame('failed', AiOperation::sole()->status);
        $this->assertSame('specific failure', AiOperation::sole()->error);
    }

    public function test_retry_uses_uuid_and_actually_requeues_the_job(): void
    {
        Http::preventStrayRequests();
        config(['queue.default' => 'database', 'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'sqlite']);
        app()->forgetInstance('queue.failer');
        $update = $this->update();
        $queue = Queue::connection('database');
        $queuedId = $queue->push(new ProcessTelegramMessage($update->id), '', 'assistant');
        $payload = DB::table('jobs')->where('id', $queuedId)->value('payload');
        DB::table('jobs')->where('id', $queuedId)->delete();
        $uuid = json_decode($payload, true)['uuid'];
        $id = DB::table('failed_jobs')->insertGetId(['uuid' => $uuid, 'connection' => 'database',
            'queue' => 'assistant', 'payload' => $payload, 'exception' => 'test failure', 'failed_at' => now()]);

        $tool = new RetryFailedJob($update);
        $request = new Request(['failed_job_id' => $id]);
        $result = json_decode($tool->handle($request), true);
        $this->assertTrue($result['queued']);
        $this->assertDatabaseMissing('failed_jobs', ['id' => $id]);
        $this->assertDatabaseCount('jobs', 1);
        $tool->handle($request);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_telegram_displays_an_active_variant_instead_of_disabled_default(): void
    {
        $product = Product::create(['title' => 'Test laptop', 'product_type' => 'laptop',
            'category_id' => Category::where('slug', 'laptops')->value('id')]);
        $product->variants()->create(['price' => 999, 'currency' => 'CZK', 'is_default' => true, 'is_active' => false]);
        $product->variants()->create(['price' => 777, 'currency' => 'CZK', 'is_default' => false, 'is_active' => true]);
        $caption = app(ProductCardPresenter::class)->caption($product);
        $this->assertStringContainsString('777 CZK', $caption);
        $this->assertStringNotContainsString('999 CZK', $caption);
    }

    public function test_cancelled_photo_and_voice_do_not_download_or_call_ai(): void
    {
        Queue::fake();
        $update = $this->update();
        $update->update(['status' => 'cancelled', 'processed_at' => now()]);
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldNotReceive('getFile');
        $telegram->shouldNotReceive('sendMessage');
        foreach ([TranscribeTelegramPhoto::class, TranscribeTelegramVoice::class] as $job) {
            (new $job($update->id))->handle($telegram, app(AiErrorPresenter::class));
        }
        Queue::assertNothingPushed();
        $this->assertSame('cancelled', $update->fresh()->status);
    }

    public function test_cancellation_during_photo_download_prevents_ai_and_dispatch(): void
    {
        Queue::fake();
        $update = $this->update();
        $update->update(['payload' => ['message' => ['photo' => [['file_id' => 'test']]]]]);
        $telegram = Mockery::mock(TelegramClient::class);
        $telegram->shouldReceive('getFile')->once()->andReturn(['result' => ['file_path' => 'test.jpg']]);
        $telegram->shouldReceive('downloadFile')->once()->andReturnUsing(function () use ($update): string {
            $update->update(['status' => 'cancelled', 'processed_at' => now()]);

            return 'unused';
        });
        $telegram->shouldNotReceive('sendMessage');
        (new TranscribeTelegramPhoto($update->id))->handle($telegram, app(AiErrorPresenter::class));
        Queue::assertNothingPushed();
        $this->assertSame('cancelled', $update->fresh()->status);
    }
}
