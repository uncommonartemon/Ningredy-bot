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
            'product-images.minimum_side' => 500,
            'product-images.browser_fallback.confirmed_gallery_minimum_side' => 400,
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
        Queue::assertPushed(RestageDraftGalleryPhotos::class, fn (RestageDraftGalleryPhotos $job): bool =>
            $job->draftId === $draft->id && $job->telegramUpdateId > 0
        );
        Queue::assertNotPushed(StoreProductImages::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Автоматически ищу замену'));
    }
}
