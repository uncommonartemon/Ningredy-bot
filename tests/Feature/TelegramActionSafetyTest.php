<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\DeleteProductPhotos;
use App\Ai\Tools\ListPendingDrafts;
use App\Ai\Tools\RefindProductPhotos;
use App\Ai\Tools\ReorderProductPhotos;
use App\Ai\Tools\RetrainDraftGalleryRecipe;
use App\Ai\Tools\UpdateProduct;
use App\Ai\Tools\UpscaleProductPhoto;
use App\Exceptions\ProductSearchCancelled;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\RefindCatalogPhotos;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Products\CatalogPhotoSelection;
use App\Services\Products\OperatorDomainHintRecorder;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class TelegramActionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 910000;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['123', '456']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
    }

    private function update(string $user = '123', string $chat = '900'): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => ++$this->sequence, 'telegram_user_id' => $user,
            'chat_id' => $chat, 'text' => 'Audit fixture', 'status' => 'received', 'payload' => []]);
    }

    private function draft(TelegramUpdate $owner): ProductDraft
    {
        $run = AiRun::create(['telegram_update_id' => $owner->id, 'provider' => 'openai', 'model' => 'audit',
            'status' => 'completed', 'prompt' => 'Isolated audit fixture', 'started_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $owner->id,
            'ai_run_id' => $run->id, 'sources' => [],
            'requested_by_telegram_user_id' => $owner->telegram_user_id, 'status' => 'pending_review',
            'title' => 'Private audit draft A', 'primary_source_url' => 'https://example.test/product',
            'image_urls' => ['https://example.test/original.jpg'], 'specifications' => []]);
    }

    public function test_foreign_operator_cannot_queue_retrain(): void
    {
        $draft = $this->draft($this->update());
        $other = $this->update('456', '901');
        try {
            (new RetrainDraftGalleryRecipe($other))->handle(new Request(['draft_id' => $draft->id]));
            $this->fail('Foreign draft must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('another operator', $error->getMessage());
        }
        Queue::assertNothingPushed();
    }

    public function test_pending_draft_tool_only_returns_current_owner_and_chat(): void
    {
        $draft = $this->draft($this->update());
        $data = json_decode((string) (new ListPendingDrafts($this->update('456', '901')))->handle(new Request([])), true);
        $this->assertSame([], $data['drafts']);
        $own = json_decode((string) (new ListPendingDrafts($draft->telegramUpdate))->handle(new Request([])), true);
        $this->assertSame($draft->id, $own['drafts'][0]['id']);
    }

    public function test_ai_cannot_deliver_other_chat_draft_or_rebind_controls(): void
    {
        $draft = $this->draft($this->update());
        $other = $this->update('456', '901');
        ServerAssistantAgent::fake([['response_type' => 'draft_ready', 'message' => 'Audit',
            'draft_id' => $draft->id, 'product_ids' => [], 'operation_ids' => []]])->preventStrayPrompts();
        (new ProcessTelegramMessage($other->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));
        Http::assertNotSent(fn (HttpRequest $request) => ($request['chat_id'] ?? null) === '901'
            && str_contains((string) ($request['text'] ?? ''), 'Private audit draft A'));
        $this->assertNull($draft->fresh()->telegram_review_chat_id);
    }

    public function test_reset_preserves_another_operator_in_the_same_group(): void
    {
        $other = $this->update('456', '-100900');
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => 8, 'from' => ['id' => 123], 'chat' => ['id' => -100900], 'text' => '/reset',
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->assertSame('received', $other->fresh()->status);
    }

    public function test_training_preserves_new_generation_if_changed_during_training(): void
    {
        $owner = $this->update();
        $draft = $this->draft($owner);
        $replacement = $this->update();
        $trainer = $this->mock(ProductGalleryRecipeTrainer::class);
        $trainer->shouldReceive('train')->once()->andReturnUsing(function () use ($draft, $replacement) {
            ProductDraft::whereKey($draft->id)->update(['telegram_update_id' => $replacement->id,
                'image_urls' => ['https://example.test/new-generation.jpg']]);

            return ['https://example.test/stale-1.jpg', 'https://example.test/stale-2.jpg'];
        });
        $hints = $this->mock(OperatorDomainHintRecorder::class);
        $hints->shouldReceive('remember')->once();
        try {
            (new TrainDraftGalleryRecipe($draft->id, $owner->id, '900', expectedDraftTelegramUpdateId: $owner->id))
                ->handle($trainer, app(TelegramClient::class), $hints);
            $this->fail('Stale generation must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('изменились', $error->getMessage());
        }
        $this->assertSame($replacement->id, $draft->fresh()->telegram_update_id);
        $this->assertSame(['https://example.test/new-generation.jpg'], $draft->fresh()->image_urls);
    }

    public function test_failed_webhook_reply_retries_without_losing_the_update(): void
    {
        $payload = ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => 9, 'from' => ['id' => 123], 'chat' => ['id' => 900], 'text' => '/help',
        ]];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'audit outage'], 503)]);
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertStatus(500);
        $this->assertNull(TelegramUpdate::where('update_id', $payload['update_id'])->firstOrFail()->processed_at);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 701]])]);
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();
        Http::assertSentCount(1);
        $this->assertNotNull(TelegramUpdate::where('update_id', $payload['update_id'])->firstOrFail()->processed_at);
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk()->assertJson(['duplicate' => true]);
        Http::assertSentCount(1);
    }

    public function test_source_ban_confirmation_rejects_a_changed_domain(): void
    {
        $draft = $this->draft($this->update());
        $press = function (string $action) use ($draft) {
            return $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'callback_query' => [
                'id' => 'audit-'.$this->sequence, 'from' => ['id' => 123], 'data' => "draft:{$action}:{$draft->id}",
                'message' => ['message_id' => 700, 'chat' => ['id' => 900]],
            ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
        };
        $press('source-block')->assertOk();
        $state = app(DraftTelegramInteractionState::class)->get('900', '123');
        $draft->refresh()->update(['primary_source_url' => 'https://changed.example.test/product']);
        $router = $this->mock(ProductGalleryRecipeRouter::class);
        $router->shouldNotReceive('blockDomain');
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'callback_query' => [
            'id' => 'audit-ban-confirm', 'from' => ['id' => 123], 'data' => "draft:confirm:{$draft->id}:{$state['token']}",
            'message' => ['message_id' => 700, 'chat' => ['id' => 900]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $newState = app(DraftTelegramInteractionState::class)->get('900', '123');
        $this->assertSame('changed.example.test', $newState['host']);
    }

    public function test_reset_delivery_retry_does_not_cancel_requests_created_after_the_reset(): void
    {
        $old = $this->update();
        $payload = ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => 9, 'from' => ['id' => 123], 'chat' => ['id' => 900], 'text' => '/reset',
        ]];
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 503)]);
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertStatus(500);
        $new = $this->update();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 701]])]);
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk();
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('received', $new->fresh()->status);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], 'запросов — 1'));
        $this->postJson('/api/telegram/webhook', $payload, $headers)->assertOk()->assertJson(['duplicate' => true]);
        Http::assertSentCount(1);
    }

    public function test_cancelled_update_cannot_mutate_catalog_through_ai_tool(): void
    {
        $category = Category::create(['name' => 'Audit category', 'slug' => 'audit-category']);
        $product = Product::create(['category_id' => $category->id, 'canonical_key' => 'audit-product',
            'slug' => 'audit-product', 'title' => 'Original reviewed title']);
        $update = $this->update();
        $update->update(['status' => 'cancelled', 'cancel_requested_at' => now(), 'processed_at' => now()]);
        try {
            (new UpdateProduct($update))->handle(new Request([
                'product_id' => $product->id, 'title' => 'Written after cancellation',
            ]));
            $this->fail('Cancelled write must be rejected.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('Original reviewed title', $product->fresh()->title);
        }
    }

    private function catalogFixture(): array
    {
        $category = Category::create(['name' => 'Audit category', 'slug' => 'audit-category']);
        $product = Product::create(['category_id' => $category->id, 'canonical_key' => 'audit-product',
            'slug' => 'audit-product', 'title' => 'Two configurations', 'is_active' => true, 'published_at' => now()]);
        $a = $product->variants()->create(['fingerprint' => 'a', 'name' => 'A', 'is_default' => false, 'is_active' => true]);
        $b = $product->variants()->create(['fingerprint' => 'b', 'name' => 'B', 'is_default' => true, 'is_active' => true]);
        $media = [];
        foreach ([$a, $b] as $index => $variant) {
            $path = 'products/'.$product->id.'/audit-'.$index.'.png';
            Storage::disk('public')->put($path, 'original audit fixture');
            $media[] = $product->media()->create(['product_variant_id' => $variant->id, 'type' => 'image',
                'disk' => 'public', 'path' => $path, 'verification_status' => 'verified', 'sort_order' => $index,
                'is_primary' => true]);
        }

        return [$product, $a, $b, ...$media];
    }

    public function test_delete_position_one_targets_the_displayed_configuration(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $telegram = $this->mock(TelegramClient::class);
        $telegram->shouldReceive('sendMediaGroupFiles')->once()->with('900', [
            Storage::disk('public')->path($mediaB->path),
        ], \Mockery::type('string'))->andReturn(['ok' => true]);
        app(ProductCardPresenter::class)->send($telegram, '900', $product);
        (new DeleteProductPhotos($this->update()))->handle(new Request(['product_id' => $product->id, 'positions' => [1]]));
        $this->assertNotNull($mediaA->fresh());
        $this->assertNull($mediaB->fresh());
    }

    public function test_refind_never_pairs_source_a_with_target_b_and_carries_current_caller(): void
    {
        [$product, $a, $b] = $this->catalogFixture();
        $draft = $this->draft($this->update());
        $draft->update(['status' => 'approved', 'approved_product_id' => $product->id, 'approved_variant_id' => $a->id]);
        $other = $this->update('456', '901');
        $result = json_decode((string) (new RefindProductPhotos($other))->handle(new Request([
            'product_id' => $product->id, 'replace_positions' => [1], 'fresh' => false,
        ])), true);
        $this->assertFalse($result['ok']);
        Queue::assertNotPushed(RefindCatalogPhotos::class);
        $result = json_decode((string) (new RefindProductPhotos($other))->handle(new Request([
            'product_id' => $product->id, 'replace_positions' => [1], 'fresh' => false, 'variant_id' => $a->id,
        ])), true);
        $this->assertTrue($result['ok']);
        Queue::assertPushed(RefindCatalogPhotos::class, fn ($job) => $job->sourceDraftId === $draft->id
            && $job->variantId === $a->id && $job->telegramUpdateId === $other->id);
    }

    public function test_published_upscale_preserves_original_on_cancellation(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $update = $this->update();
        $bitmap = imagecreatetruecolor(3, 3);
        imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 255, 0, 0));
        ob_start();
        imagepng($bitmap);
        $generated = ob_get_clean();
        imagedestroy($bitmap);
        Image::fake(function () use ($update, $generated) {
            $update->update(['cancel_requested_at' => now()]);

            return base64_encode($generated);
        })->preventStrayImages();
        $this->mock(ProductImageVisionVerifier::class)->shouldNotReceive('select');
        try {
            (new UpscaleProductPhoto($update))->handle(new Request([
                'product_id' => $product->id, 'position' => 1,
            ]));
            $this->fail('Cancelled image must be rejected.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('original audit fixture', Storage::disk('public')->get($mediaA->path));
            $this->assertSame('original audit fixture', Storage::disk('public')->get($mediaB->path));
            $this->assertSame($mediaB->path, $mediaB->fresh()->path);
        }
    }

    public function test_published_upscale_verifies_new_pixels_and_commits_once_without_transaction_during_ai(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $update = $this->update();
        $level = DB::transactionLevel();
        $bitmap = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($bitmap);
        $generated = ob_get_clean();
        imagedestroy($bitmap);
        $calls = 0;
        Image::fake(function () use ($generated, $level, &$calls) {
            $calls++;
            $this->assertSame($level, DB::transactionLevel());

            return base64_encode($generated);
        })->preventStrayImages();
        $this->mock(ProductImageVisionVerifier::class)->shouldReceive('select')->once()
            ->withArgs(function ($draft, $images, $limit, $updateId) use ($product, $update, $level) {
                $this->assertSame($level, DB::transactionLevel());

                return $draft->title === $product->title && $images[0]['image'] instanceof \GdImage
                    && $limit === 1 && $updateId === $update->id;
            })->andReturn([['vision_score' => 94, 'vision_model' => 'verified-fixture', 'vision_reason' => 'Same model and color']]);
        $tool = new UpscaleProductPhoto($update);
        $request = new Request(['product_id' => $product->id, 'position' => 1]);
        $first = json_decode((string) $tool->handle($request), true);
        $second = json_decode((string) $tool->handle($request), true);
        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
        $this->assertSame($mediaB->id, $first['media_id']);
        $this->assertNotSame($mediaB->path, $mediaB->fresh()->path);
        $this->assertSame('verified-fixture', $mediaB->fresh()->verification_model);
        $this->assertSame($mediaA->path, $mediaA->fresh()->path);
        $this->assertSame('original audit fixture', Storage::disk('public')->get($mediaA->path));
    }

    public function test_stale_displayed_gallery_cannot_delete_a_new_position(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $selection = app(CatalogPhotoSelection::class);
        $selection->remember('900', $product, $b, collect([$mediaB]));
        $mediaB->update(['sort_order' => 10]);
        try {
            (new DeleteProductPhotos($this->update()))->handle(new Request(['product_id' => $product->id, 'positions' => [1]]));
            $this->fail('Stale displayed gallery must require reopening the card.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Откройте /product', $error->getMessage());
        }
        $this->assertNotNull($mediaB->fresh());
        $this->assertNotNull($mediaA->fresh());
    }

    public function test_owner_retrain_replay_does_not_queue_or_set_a_new_busy_marker(): void
    {
        $owner = $this->update();
        $draft = $this->draft($owner);
        $tool = new RetrainDraftGalleryRecipe($owner);
        $request = new Request(['draft_id' => $draft->id]);
        $first = (string) $tool->handle($request);
        Cache::forget("draft-gallery-retrain:{$draft->id}:queued");
        $this->assertSame($first, (string) $tool->handle($request));
        $this->assertNull(Cache::get("draft-gallery-retrain:{$draft->id}:queued"));
        Queue::assertPushed(TrainDraftGalleryRecipe::class, 1);
    }

    public function test_refind_stages_under_current_caller_and_commits_only_selected_variant_once(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $source = $this->draft($this->update());
        $source->update(['status' => 'approved', 'approved_product_id' => $product->id, 'approved_variant_id' => $b->id]);
        $caller = $this->update('456', '901');
        $selection = app(CatalogPhotoSelection::class);
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function ($draft, $progress, $updateId) use ($caller, $source) {
            $this->assertNotSame($source->id, $draft->id);
            $this->assertSame($caller->id, $updateId);
            $this->assertSame($caller->id, $draft->telegram_update_id);
            $this->assertSame('photo_refresh', $draft->status);
            $path = 'drafts/'.$draft->id.'/verified.webp';
            Storage::disk('public')->put($path, 'verified new pixels');
            $draft->media()->create(['type' => 'image', 'disk' => 'public', 'path' => $path,
                'verification_status' => 'verified', 'checksum' => hash('sha256', 'verified new pixels'), 'is_primary' => true]);
            $draft->update(['images_staged_at' => now(), 'gallery_status' => 'complete',
                'specifications_reconciled_source_url' => $draft->primary_source_url]);

            return 1;
        });
        $job = new RefindCatalogPhotos($product->id, $b->id, $source->id, $caller->id,
            $selection->fingerprint($product, $b), [$mediaB->id]);
        $job->handle($storage, app(TelegramClient::class), $selection);
        $job->handle($storage, app(TelegramClient::class), $selection);
        $this->assertNotNull($mediaA->fresh());
        $this->assertNull($mediaB->fresh());
        $this->assertSame(1, $product->media()->where('product_variant_id', $b->id)->count());
        $current = $product->media()->where('product_variant_id', $b->id)->firstOrFail();
        $this->assertSame('verified new pixels', Storage::disk('public')->get($current->path));
        Http::assertNotSent(fn (HttpRequest $request) => ($request['chat_id'] ?? null) === '900');
        $this->assertSame($source->telegram_update_id, $source->fresh()->telegram_update_id);
    }

    public function test_refind_cancelled_during_discovery_keeps_public_gallery(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $source = $this->draft($this->update());
        $source->update(['status' => 'approved', 'approved_product_id' => $product->id, 'approved_variant_id' => $b->id]);
        $caller = $this->update();
        $selection = app(CatalogPhotoSelection::class);
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function () use ($caller) {
            $caller->update(['cancel_requested_at' => now()]);

            return 0;
        });
        $job = new RefindCatalogPhotos($product->id, $b->id, $source->id, $caller->id,
            $selection->fingerprint($product, $b), [$mediaB->id]);
        try {
            $job->handle($storage, app(TelegramClient::class), $selection);
            $this->fail('Cancelled refind cannot commit.');
        } catch (ProductSearchCancelled) {
            $this->assertNotNull($mediaB->fresh());
            $this->assertNotNull($mediaA->fresh());
            $this->assertSame(2, $product->media()->count());
        }
    }

    public function test_shared_fallback_gallery_is_preserved_for_both_variants_by_every_photo_action(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $mediaA->delete();
        $mediaB->update(['product_variant_id' => null]);
        Queue::fake();
        $update = $this->update();
        $actions = [
            [new DeleteProductPhotos($update), ['positions' => [1]]],
            [new ReorderProductPhotos($update), ['new_order' => [1]]],
            [new RefindProductPhotos($update), ['replace_positions' => [1]]],
            [new UpscaleProductPhoto($update), ['position' => 1]],
        ];
        Image::fake()->preventStrayImages();
        foreach ($actions as [$tool, $payload]) {
            try {
                $result = json_decode((string) $tool->handle(new Request(['product_id' => $product->id,
                    'variant_id' => $a->id, ...$payload])), true);
                $this->assertFalse($result['ok']);
                $this->assertStringContainsString('общая галерея', $result['error']);
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('общая галерея', $error->getMessage());
            }
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_runs', 0);
        $selection = app(CatalogPhotoSelection::class);
        $this->assertSame([$mediaB->id], $selection->gallery($product, $a)->pluck('id')->all());
        $this->assertSame([$mediaB->id], $selection->gallery($product, $b)->pluck('id')->all());
        $this->assertNull($mediaB->fresh()->product_variant_id);
    }

    public function test_group_gallery_context_is_isolated_by_operator(): void
    {
        [$product, $a, $b, $mediaA, $mediaB] = $this->catalogFixture();
        $selection = app(CatalogPhotoSelection::class);
        $selection->remember('-100900', $product, $a, collect([$mediaA]), '123');
        $selection->remember('-100900', $product, $b, collect([$mediaB]), '456');
        (new DeleteProductPhotos($this->update('123', '-100900')))
            ->handle(new Request(['product_id' => $product->id, 'positions' => [1]]));
        $this->assertNull($mediaA->fresh());
        $this->assertNotNull($mediaB->fresh());
    }

    public function test_verification_snapshot_uses_current_variant_identifiers(): void
    {
        [$product, $a, $b] = $this->catalogFixture();
        $a->update(['sku' => 'CURRENT-A', 'mpn' => 'MPN-A', 'gtin' => '1234567890123']);
        $b->update(['sku' => 'CURRENT-B', 'mpn' => 'MPN-B', 'gtin' => '1234567890124']);
        $definition = AttributeDefinition::create(['key' => 'sku', 'label' => 'SKU', 'data_type' => 'text']);
        $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => 'STALE-SKU']);
        $selection = app(CatalogPhotoSelection::class);
        foreach ([$a, $b] as $variant) {
            $snapshot = $selection->verificationDraft($product, $variant);
            $specs = collect($snapshot->specifications)->keyBy('key');
            $this->assertSame($variant->sku, $specs['sku']['value']);
            $this->assertSame($variant->mpn, $specs['mpn']['value']);
            $this->assertSame($variant->gtin, $specs['gtin']['value']);
        }
    }
}
