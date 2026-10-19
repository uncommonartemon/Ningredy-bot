<?php

namespace Tests\Feature;

use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcceptAsIsDraftApprovalTest extends TestCase
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
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    public function test_verified_photos_with_unreconciled_specifications_can_be_accepted_after_seeing_what_is_unreconciled(): void
    {
        $draft = $this->unreconciledDraft();

        $this->press("draft:asis:{$draft->id}", 4001);
        $this->assertSame('pending_review', $draft->fresh()->status);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Принять черновик #'.$draft->id.' как есть')
            && str_contains((string) ($request['text'] ?? ''), 'sku: The supplied code is not shown on any page.'));

        $this->press("draft:asisok:{$draft->id}:{$draft->telegram_update_id}", 4002);

        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertDatabaseHas('products', ['title' => 'Alienware 16X Aurora']);
        $operation = AiOperation::query()->where('action', 'add_draft_to_catalog_as_is')->sole();
        $this->assertSame('sku', $operation->payload['accepted_unreconciled_fields'][0]['key']);
    }

    public function test_a_stale_confirmation_and_the_plain_add_button_still_refuse_an_unreconciled_card(): void
    {
        $draft = $this->unreconciledDraft();

        $this->press("draft:asisok:{$draft->id}:999999", 4003);
        $this->press("draft:add:{$draft->id}", 4004);

        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertDatabaseMissing('products', ['title' => 'Alienware 16X Aurora']);
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => $updateId, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function unreconciledDraft(): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => 3990, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => 90, 'text' => 'Alienware 16X Aurora; AWAC16251-9765BLU-PDE', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test',
            'status' => 'completed', 'prompt' => 'Find', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Alienware 16X Aurora', 'brand' => 'Alienware',
            'model' => 'AC16251', 'description' => 'Gaming laptop.', 'category' => 'laptops', 'product_type' => 'laptop',
            'specifications' => [['key' => 'sku', 'name' => 'SKU', 'value' => 'AWAC16251-9765BLU-PDE']],
            'sources' => [['title' => 'Dell', 'url' => 'https://www.dell.com/ac16251', 'type' => 'manufacturer']],
            'primary_source_url' => 'https://www.dell.com/ac16251', 'image_urls' => [], 'confidence' => 0.9,
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'specifications_unreconciled', 'images_staged_at' => now()]);
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/primary.webp",
            'source_url' => 'https://www.dell.com/a.webp', 'role' => 'primary', 'mime_type' => 'image/webp',
            'width' => 1600, 'height' => 1600, 'file_size' => 100, 'checksum' => hash('sha256', 'a'),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);
        Storage::disk('public')->put("drafts/{$draft->id}/primary.webp", 'image');
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'domain' => 'www.dell.com', 'product_url' => 'https://www.dell.com/ac16251', 'actor' => 'ai',
            'phase' => 'card_assembly', 'action' => 'reconcile_card', 'status' => 'interrupted', 'decision' => 'not_ready',
            'output' => ['repair_context' => ['unresolved_fields' => [['key' => 'sku', 'status' => 'unknown',
                'reason' => 'The supplied code is not shown on any page.']]]]]);

        return $draft;
    }
}
