<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\PhotoSourceNotice;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use Tests\TestCase;

class PhotoSourceContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_context_and_repair_state_roundtrip_without_changing_legacy_defaults(): void
    {
        $draft = $this->draft();
        $this->assertNull($draft->fresh()->photo_source_context);
        $this->assertNull($draft->fresh()->card_repair_state);
        $context = $this->context();
        $draft->update(['photo_source_context' => $context, 'card_repair_state' => ['attempts' => 2, 'last_error' => 'missing_field']]);
        $media = $draft->media()->create(['disk' => 'public', 'path' => 'drafts/fixture.webp', 'checksum' => hash('sha256', 'fixture'), 'photo_source_context' => $context]);
        $this->assertSame($context, $draft->fresh()->photo_source_context);
        $this->assertSame(2, $draft->fresh()->card_repair_state['attempts']);
        $this->assertSame($context, $media->fresh()->photo_source_context);
        $this->assertFalse($draft->fresh()->reconciliationPending(), 'Metadata writes must not invalidate fact reconciliation.');

        $product = $this->product();
        $published = $product->media()->create(['type' => 'image', 'url' => '/fixture.webp', 'photo_source_context' => $context]);
        $this->assertSame($context, $published->fresh()->photo_source_context);
    }

    public function test_product_page_exposes_only_the_safe_notice_for_actual_media(): void
    {
        $product = $this->product();
        $product->media()->create(['type' => 'image', 'url' => '/compatible.webp', 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 0,
            'photo_source_context' => $this->context()]);
        $product->media()->create(['type' => 'image', 'url' => '/exact.webp', 'verification_status' => 'verified', 'sort_order' => 1,
            'photo_source_context' => $this->context('exact')]);
        $product->media()->create(['type' => 'image', 'url' => '/legacy.webp', 'verification_status' => 'manual', 'sort_order' => 2]);
        $product->media()->create(['type' => 'image', 'url' => '/pending.webp', 'verification_status' => 'pending', 'sort_order' => 3,
            'photo_source_context' => $this->context()]);

        $this->get(route('products.show', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Products/Show')->has('product.media', 3)
            ->where('product.media.0.photo_notice.source_model', 'A8WHG-019US')
            ->where('product.media.0.photo_notice.differences.0', 'На фото американская клавиатура; товар с немецкой клавиатурой.')
            ->missing('product.media.0.photo_notice.target_fingerprint')
            ->missing('product.media.0.photo_source_context')
            ->where('product.media.1.photo_notice', null)
            ->where('product.media.2.photo_notice', null));
    }

    public function test_telegram_approval_controls_and_caption_disclose_actual_retained_gallery_difference(): void
    {
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        $draft = $this->draft();
        // The draft-level attempted gallery is different; retained frame provenance wins.
        $draft->update(['photo_source_context' => $this->context('exact')]);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/fixture.webp', 'verification_status' => 'verified', 'checksum' => hash('sha256', 'fixture'),
            'photo_source_context' => $this->context()]);
        $presenter = app(DraftTelegramPresenter::class);

        $caption = (new ReflectionMethod($presenter, 'caption'))->invoke($presenter, $draft, '');
        $this->assertStringContainsString('Фото другой комплектации', $caption);
        $this->assertStringContainsString('A8WHG-019US', $caption);
        $this->assertStringContainsString('американская клавиатура', $caption);
        $this->assertLessThanOrEqual(1024, mb_strlen($caption));
        $presenter->sendControls(app(TelegramClient::class), '2', $draft);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'A8WHG-019US')
            && str_contains((string) $request['text'], 'американская клавиатура'));
    }

    public function test_legacy_and_uncertain_photos_never_claim_compatible_chassis(): void
    {
        $this->assertNull(PhotoSourceNotice::fromContext(null));
        $this->assertNull(PhotoSourceNotice::fromContext($this->context('uncertain')));
        $this->assertNull(PhotoSourceNotice::fromContext($this->context('exact')));
        $context = $this->context();
        $context['_photo_applicability']['source_model'] = '<b>A8WHG</b>';
        $context['_photo_applicability']['differences'] = ['<script>bad()</script>', [], null, '   '];
        $notice = PhotoSourceNotice::fromContext($context);
        $this->assertSame('A8WHG', $notice['source_model']);
        $this->assertSame(['bad()'], $notice['differences']);
        $this->assertSame(['source_model', 'differences'], array_keys($notice));
    }

    public function test_a_processor_sticker_kept_on_the_photos_is_named_for_the_operator_and_flagged_for_the_buyer(): void
    {
        // Live #214: Lenovo renders of the exact SKU carry a Core i7 badge on a Core i5 card.
        $context = ['url' => 'https://icecat.example/12ud00c8uk', '_photo_applicability' => ['verdict' => 'exact'],
            '_photo_visual_review' => ['scope' => 'current_downloaded_frames', 'differences' => [],
                'configuration_markings' => ['Intel Core i7', ' ', '<b>Intel Core i7</b>']]];

        $notice = PhotoSourceNotice::fromContext($context);

        $this->assertSame(['Intel Core i7'], $notice['configuration_markings']);
        $this->assertTrue($notice['markings_may_differ']);
        $this->assertStringContainsString('На фото наклейка: Intel Core i7 — наклейки процессора и видеокарты на фото производителя могут отличаться',
            PhotoSourceNotice::russian($context));
        // Markings of another scope (an old source's review) are not this photo's.
        $context['_photo_visual_review']['scope'] = 'source_metadata';
        $this->assertNull(PhotoSourceNotice::fromContext($context));
    }

    private function context(string $verdict = 'compatible'): array
    {
        return ['url' => 'https://shop.example/other-sku', 'private_diagnostic' => 'Never exposed',
            '_photo_applicability' => ['verdict' => $verdict, 'source_model' => 'A8WHG-019US',
                'target_fingerprint' => 'internal-hash', 'reason' => 'Internal assessment',
                'differences' => ['На фото американская клавиатура; товар с немецкой клавиатурой.']]];
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 67201, 'telegram_user_id' => '1', 'chat_id' => '2',
            'payload' => [], 'status' => 'completed', 'text' => 'MSI Vector A16 HX']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'fixture', 'started_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'status' => 'pending_review', 'requested_by_telegram_user_id' => '1', 'title' => 'MSI Vector',
            'brand' => 'MSI', 'model' => 'A8WHG-018', 'color' => 'Gray', 'sources' => [], 'specifications' => [], 'image_urls' => [],
            'primary_source_url' => 'https://facts.example/exact-sku',
            'specifications_reconciled_source_url' => 'https://facts.example/exact-sku',
            'gallery_status' => 'complete', 'images_staged_at' => now()]);
    }

    private function product(): Product
    {
        return Product::create(['canonical_key' => 'photo-context-fixture', 'slug' => 'photo-context-fixture',
            'category_id' => Category::where('slug', 'laptops')->firstOrFail()->id,
            'brand_id' => Brand::firstOrCreate(['slug' => 'msi'], ['name' => 'MSI'])->id,
            'title' => 'MSI Vector', 'model' => 'A8WHG-018', 'product_type' => 'laptop',
            'status' => 'published', 'is_active' => true, 'published_at' => now()]);
    }
}
