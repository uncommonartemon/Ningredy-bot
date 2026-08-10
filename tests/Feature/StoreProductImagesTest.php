<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramMessageLifecycle;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreProductImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_post_includes_photo_source_and_token_usage(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        [$product, $variant, $draft] = $this->approvedProductWithMedia();
        // usageFootnote() only counts ai_runs created from the job's own
        // start onward (so it doesn't also count the earlier research step);
        // force created_at into the future so it always lands after that
        // cutoff regardless of how fast this test actually runs.
        $imageSearchRun = AiRun::query()->create([
            'telegram_update_id' => $draft->telegram_update_id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'find images',
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 100],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $imageSearchRun->forceFill(['created_at' => now()->addMinute()])->saveQuietly();

        (new StoreProductImages($product->id, $variant->id, $draft->id))->handle(
            app(ProductImageStorage::class),
            app(TelegramClient::class),
            app(DraftTelegramMessageLifecycle::class),
        );

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_ends_with($request->url(), '/sendMediaGroup')) {
                return false;
            }
            $parts = collect($request->data())->keyBy('name');
            $mediaJson = (string) ($parts->get('media')['contents'] ?? '');
            $media = json_decode($mediaJson, true) ?? [];
            $caption = (string) ($media[0]['caption'] ?? '');

            return str_contains($caption, 'example.com')
                && str_contains($caption, 'Токены на поиск фото: 600');
        });
    }

    public function test_staged_draft_photos_are_adopted_and_the_added_product_album_is_sent(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        [$product, $variant, $draft] = $this->approvedProductWithMedia();
        $draft->update(['images_staged_at' => now()]);

        foreach (range(1, 2) as $position) {
            $path = "drafts/{$draft->id}/staged-{$position}.webp";
            Storage::disk('public')->put($path, "staged-{$position}");
            $draft->media()->create([
                'disk' => 'public',
                'path' => $path,
                'source_url' => "https://shop.example/staged-{$position}.jpg",
                'role' => $position === 1 ? 'primary' : 'secondary',
                'mime_type' => 'image/webp',
                'checksum' => hash('sha256', "staged-{$position}"),
                'verification_status' => 'verified',
                'sort_order' => $position - 1,
                'is_primary' => $position === 1,
            ]);
        }

        (new StoreProductImages($product->id, $variant->id, $draft->id))->handle(
            app(ProductImageStorage::class),
            app(TelegramClient::class),
            app(DraftTelegramMessageLifecycle::class),
        );

        $this->assertDatabaseCount('product_draft_media', 0);
        $this->assertSame(5, $product->media()->count());
        Http::assertSent(function (HttpRequest $request) use ($product): bool {
            if (! str_ends_with($request->url(), '/sendMediaGroup')) {
                return false;
            }

            $parts = collect($request->data())->keyBy('name');
            $media = json_decode((string) ($parts->get('media')['contents'] ?? ''), true) ?? [];

            return str_contains((string) ($media[0]['caption'] ?? ''), "Товар #{$product->id} добавлен в каталог");
        });
    }

    public function test_existing_draft_album_is_finalized_in_place_and_controls_are_deleted(): void
    {
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        [$product, $variant, $draft] = $this->approvedProductWithMedia();
        $lifecycle = app(DraftTelegramMessageLifecycle::class);
        $originalCaption = "🆕 Черновик #{$draft->id} готов к добавлению\n\n"
            ."🏷 {$draft->title}\n\n"
            ."⚙️ Характеристики:\n🧠 Процессор: Test CPU\n💾 Память: 16 GB\n\n"
            .'📷 Фото: 3';
        $lifecycle->rememberReviewResponse(app(TelegramClient::class), $draft, '98765', [
            'ok' => true,
            'result' => [
                ['message_id' => 701],
                ['message_id' => 702],
                ['message_id' => 703],
            ],
        ], true, $originalCaption);
        $draft->forceFill(['telegram_control_message_ids' => [704]])->save();

        (new StoreProductImages($product->id, $variant->id, $draft->id))->handle(
            app(ProductImageStorage::class),
            app(TelegramClient::class),
            $lifecycle,
        );

        Http::assertSent(function (HttpRequest $request) use ($product, $originalCaption): bool {
            if (! str_ends_with($request->url(), '/editMessageCaption') || (int) $request['message_id'] !== 701) {
                return false;
            }

            $expectedCaption = preg_replace(
                '/\A[^\r\n]*/u',
                "✅ Товар #{$product->id} добавлен в каталог",
                $originalCaption,
                1,
            );

            return $request['caption'] === $expectedCaption;
        });
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/deleteMessages')
            && $request['message_ids'] === [704]);
        Http::assertNotSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMediaGroup'));

        $draft->refresh();
        $this->assertSame([701, 702, 703], $draft->telegram_review_message_ids);
        $this->assertSame([], $draft->telegram_control_message_ids);
        $this->assertNotNull($draft->telegram_review_finalized_at);
    }

    /** @return array{Product, ProductVariant, ProductDraft} */
    private function approvedProductWithMedia(): array
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->firstOrCreate(['slug' => 'lenovo'], ['name' => 'Lenovo', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'lenovo-usage-test',
            'product_type' => 'component',
            'status' => 'published',
            'slug' => 'lenovo-usage-test',
            'title' => 'Lenovo Usage Test',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'fingerprint' => 'usage-test-variant',
            'name' => 'Default',
            'is_default' => true,
            'is_active' => true,
        ]);
        // product_type "component" targets 3 images by default; pre-fill all
        // 3 so ProductImageStorage::store() sees remaining <= 0 and returns
        // immediately without running the real search pipeline.
        for ($index = 0; $index < 3; $index++) {
            Storage::disk('public')->put("products/{$product->id}/photo-{$index}.webp", "bytes-{$index}");
            $product->media()->create([
                'product_variant_id' => $variant->id,
                'type' => 'image',
                'disk' => 'public',
                'path' => "products/{$product->id}/photo-{$index}.webp",
                'source_url' => "https://www.example.com/photo-{$index}.jpg",
                'verification_status' => 'verified',
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }

        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'draft:add:1',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'research',
            'started_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => $product->title,
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
            'confidence' => 0.9,
            'approved_product_id' => $product->id,
            'approved_variant_id' => $variant->id,
        ]);

        return [$product, $variant, $draft];
    }
}
