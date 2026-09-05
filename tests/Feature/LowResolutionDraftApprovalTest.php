<?php

namespace Tests\Feature;

use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LowResolutionDraftApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Cache::flush();
        Queue::fake();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
            'product-images.minimum_width' => 500,
            'product-images.minimum_height' => 500,
        ]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_approval_automatically_queues_restage_when_a_photo_is_below_the_current_limit(): void
    {
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => 3090,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 90,
            'text' => 'Find exact laptop',
            'payload' => ['update_id' => 3090],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Find exact laptop',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Exact Laptop',
            'brand' => 'Example',
            'model' => 'EX-1',
            'description' => 'Exact laptop description.',
            'specifications' => [],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product', 'type' => 'retailer']],
            'image_urls' => [],
            'confidence' => 0.95,
            'gallery_status' => 'partial',
        ]);
        $draft->media()->create([
            'disk' => 'public',
            'path' => "drafts/{$draft->id}/small.webp",
            'source_url' => 'https://example.com/small.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => 450,
            'height' => 450,
            'file_size' => 100,
            'checksum' => hash('sha256', 'small'),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3091,
            'callback_query' => [
                'id' => 'callback-low-resolution',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:add:{$draft->id}",
                'message' => [
                    'message_id' => 91,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertDatabaseMissing('products', ['title' => 'Exact Laptop']);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3091,
            'status' => 'completed',
            'error' => null,
        ]);
        Queue::assertPushed(RestageDraftGalleryPhotos::class, fn (RestageDraftGalleryPhotos $job): bool => $job->draftId === $draft->id && $job->telegramUpdateId > 0
        );
        Queue::assertNotPushed(StoreProductImages::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Автоматически ищу замену'));
    }

    public function test_a_frame_whose_verification_never_ran_cannot_be_published_by_the_button(): void
    {
        // The last place the distinction still exists. The search keeps the
        // frames of a check that could not run - they are real photographs -
        // and marks them pending; approving them anyway would let a Vision
        // timeout finish as an approval. The button already read
        // verification_status and had never once looked at it.
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => 3290,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 290,
            'text' => 'Find exact laptop with an interrupted check',
            'payload' => ['update_id' => 3290],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Find exact laptop with an interrupted check',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Unverified Laptop',
            'brand' => 'Example',
            'model' => 'EX-2',
            'description' => 'Exact laptop description.',
            'specifications' => [],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product', 'type' => 'retailer']],
            'image_urls' => [],
            'confidence' => 0.95,
            'gallery_status' => 'partial',
        ]);
        // Large enough to clear every other gate: the only thing wrong with
        // this photograph is that nothing has confirmed it.
        $draft->media()->create([
            'disk' => 'public',
            'path' => "drafts/{$draft->id}/large.webp",
            'source_url' => 'https://example.com/large.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => 1600,
            'height' => 1200,
            'file_size' => 100,
            'checksum' => hash('sha256', 'large'),
            'verification_status' => 'pending',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3291,
            'callback_query' => [
                'id' => 'callback-unverified',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:add:{$draft->id}",
                'message' => [
                    'message_id' => 291,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertDatabaseMissing('products', ['title' => 'Unverified Laptop']);
        Queue::assertNotPushed(StoreProductImages::class);
        // Not a quality problem, and the operator is told the difference: the
        // check itself did not run, so the search continues rather than
        // hunting for replacements for a photograph nothing is wrong with.
        Queue::assertPushed(RestageDraftGalleryPhotos::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Проверка фотографий не состоялась'));
    }

    public function test_approval_automatically_queues_restage_when_the_draft_has_no_photos(): void
    {
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => 3190,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 190,
            'text' => 'Find exact laptop without photos',
            'payload' => ['update_id' => 3190],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Find exact laptop without photos',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Exact Laptop Without Photos',
            'brand' => 'Example',
            'model' => 'EX-2',
            'description' => 'Exact laptop description.',
            'specifications' => [],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product-2', 'type' => 'retailer']],
            'image_urls' => [],
            'confidence' => 0.95,
            'gallery_status' => 'missing',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3191,
            'callback_query' => [
                'id' => 'callback-missing-photos',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:add:{$draft->id}",
                'message' => [
                    'message_id' => 191,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertDatabaseMissing('products', ['title' => 'Exact Laptop Without Photos']);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3191,
            'status' => 'completed',
            'error' => null,
        ]);
        Queue::assertPushed(RestageDraftGalleryPhotos::class, fn (RestageDraftGalleryPhotos $job): bool => $job->draftId === $draft->id && $job->telegramUpdateId > 0
        );
        Queue::assertNotPushed(StoreProductImages::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'нет фотографий'));
    }
}
