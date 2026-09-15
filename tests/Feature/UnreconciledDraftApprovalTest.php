<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
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

class UnreconciledDraftApprovalTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function draftWithGoodMedia(array $overrides = []): ProductDraft
    {
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 90,
            'text' => 'Find exact laptop',
            'payload' => [],
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
        $draft = ProductDraft::query()->create([...[
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Exact Laptop',
            'brand' => 'Example',
            'model' => 'EX-1',
            'description' => 'Exact laptop description.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB']],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product', 'type' => 'retailer']],
            'primary_source_url' => 'https://example.com/product',
            'image_urls' => [],
            'confidence' => 0.95,
            'gallery_status' => 'partial',
        ], ...$overrides]);
        $draft->media()->create([
            'disk' => 'public',
            'path' => "drafts/{$draft->id}/good.webp",
            'source_url' => 'https://example.com/good.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => 1600,
            'height' => 1200,
            'file_size' => 100,
            'checksum' => hash('sha256', 'good-'.$draft->id),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $draft;
    }

    public function test_old_publish_button_is_blocked_without_starting_another_reconciliation(): void
    {
        $draft = $this->draftWithGoodMedia([
            'specifications_reconciled_source_url' => null,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1_000_000, 9_000_000),
            'callback_query' => [
                'id' => 'callback-unreconciled',
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
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        Queue::assertNotPushed(StoreProductImages::class);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'не завершён'));
        Http::assertSent(function (ClientRequest $request) use ($draft): bool {
            $markup = $request['reply_markup'] ?? [];
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            $callbacks = collect($markup['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->all();

            return in_array("draft:reject:{$draft->id}", $callbacks, true)
                && ! in_array("draft:add:{$draft->id}", $callbacks, true);
        });
    }

    public function test_publish_succeeds_once_specifications_are_reconciled_against_the_current_source(): void
    {
        $draft = $this->draftWithGoodMedia([
            'primary_source_url' => 'https://example.com/product',
            'specifications_reconciled_source_url' => 'https://example.com/product',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1_000_000, 9_000_000),
            'callback_query' => [
                'id' => 'callback-reconciled',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:add:{$draft->id}",
                'message' => [
                    'message_id' => 92,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertDatabaseHas('products', ['title' => 'Exact Laptop']);
        Queue::assertPushed(StoreProductImages::class);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_a_draft_with_no_primary_source_url_is_not_blocked_by_this_gate(): void
    {
        // Nothing to reconcile against yet - this must not become a new,
        // unrelated way to get stuck for drafts this feature does not apply to.
        $draft = $this->draftWithGoodMedia([
            'primary_source_url' => null,
            'specifications_reconciled_source_url' => null,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => random_int(1_000_000, 9_000_000),
            'callback_query' => [
                'id' => 'callback-no-source',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:add:{$draft->id}",
                'message' => [
                    'message_id' => 93,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        $this->assertSame('approved', $draft->fresh()->status);
        Queue::assertPushed(StoreProductImages::class);
    }
}
