<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductDraftTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_product_type_wins_over_keyword_guessing(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Lenovo IdeaPad Slim 3 15AMN8', 'IdeaPad Slim 3 15AMN8', 'laptop'),
        );

        $this->assertSame('laptop', $product->product_type);
        $this->assertSame('laptops', $product->category->slug);
    }

    public function test_ideapad_without_explicit_type_is_guessed_as_laptop(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Lenovo IdeaPad Slim 3 15AMN8', 'IdeaPad Slim 3 15AMN8', null),
        );

        $this->assertSame('laptop', $product->product_type);
    }

    public function test_specs_with_cpu_ram_do_not_turn_a_laptop_into_a_component(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Ноутбук Acer Aspire Lite', 'Aspire Lite AL15', null),
        );

        $this->assertSame('laptop', $product->product_type);
    }

    public function test_compatible_draft_category_is_preserved(): void
    {
        // The AI now picks the category directly from our live category list;
        // it should be trusted over the legacy product_type -> slug mapping
        // even when the two disagree (e.g. a monitor tagged product_type
        // "other" but correctly categorized as "components").
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Dell UltraSharp U2723QE Monitor', 'U2723QE', 'component', 'components'),
        );

        $this->assertSame('components', $product->category->slug);
    }

    public function test_conflicting_draft_category_is_repaired_from_category_metadata(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Alienware 16 Area-51', 'AA16250', 'laptop', 'computers'),
        );

        $this->assertSame('laptops', $product->category->slug);
    }

    public function test_an_invalid_category_falls_back_to_the_product_type_mapping(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve(
            $this->draft('Lenovo IdeaPad Slim 3 15AMN8', 'IdeaPad Slim 3 15AMN8', 'laptop', 'not-a-real-slug'),
        );

        $this->assertSame('laptops', $product->category->slug);
    }

    public function test_catalog_identifiers_are_saved_on_the_default_variant(): void
    {
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $draft->update(['specifications' => [
            ...$draft->specifications,
            ['key' => 'sku', 'name' => 'SKU', 'value' => '82XT003GUS'],
            ['key' => 'mpn', 'name' => 'MPN', 'value' => '82XT003GUS'],
            ['key' => 'ean', 'name' => 'EAN', 'value' => '0197529234567'],
        ]]);

        $variant = app(ProductDraftWorkflow::class)->approve($draft->fresh())->defaultVariant;

        $this->assertSame('82XT003GUS', $variant->sku);
        $this->assertSame('82XT003GUS', $variant->mpn);
        $this->assertSame('0197529234567', $variant->gtin);
    }

    public function test_complete_source_verified_gallery_does_not_bypass_dimension_requirements(): void
    {
        Queue::fake([StoreProductImages::class]);
        app(AiSettings::class)->saveImageMinimumWidth(450);
        app(AiSettings::class)->saveImageMinimumHeight(450);
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $draft->update(['gallery_status' => 'complete']);
        $this->addMedia($draft, 420, 420, 'source_verified');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('450×450px');

        app(ProductDraftWorkflow::class)->approve($draft->fresh());
    }

    public function test_minimum_height_is_checked_independently_for_wide_images(): void
    {
        Queue::fake([StoreProductImages::class]);
        app(AiSettings::class)->saveImageMinimumWidth(900);
        app(AiSettings::class)->saveImageMinimumHeight(550);
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $this->addMedia($draft, 1000, 549, 'verified');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('900×550px');

        app(ProductDraftWorkflow::class)->approve($draft->fresh());
    }

    public function test_minimum_width_is_checked_independently_for_tall_images(): void
    {
        Queue::fake([StoreProductImages::class]);
        app(AiSettings::class)->saveImageMinimumWidth(900);
        app(AiSettings::class)->saveImageMinimumHeight(550);
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $this->addMedia($draft, 899, 1000, 'source_verified');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('900×550px');

        app(ProductDraftWorkflow::class)->approve($draft->fresh());
    }

    public function test_filament_image_dimensions_change_the_approval_threshold(): void
    {
        Queue::fake([StoreProductImages::class]);
        app(AiSettings::class)->saveImageMinimumWidth(430);
        app(AiSettings::class)->saveImageMinimumHeight(400);
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $this->addMedia($draft, 440, 410, 'verified');

        $product = app(ProductDraftWorkflow::class)->approve($draft->fresh());

        $this->assertSame('published', $product->status);
        Queue::assertPushed(StoreProductImages::class);
    }

    public function test_zero_minimum_height_disables_only_the_height_requirement(): void
    {
        Queue::fake([StoreProductImages::class]);
        app(AiSettings::class)->saveImageMinimumWidth(700);
        app(AiSettings::class)->saveImageMinimumHeight(0);
        $draft = $this->draft('Lenovo LOQ 15APH8', '82XT003GUS', 'laptop');
        $this->addMedia($draft, 700, 100, 'verified');

        $product = app(ProductDraftWorkflow::class)->approve($draft->fresh());

        $this->assertSame('published', $product->status);
        Queue::assertPushed(StoreProductImages::class);
    }

    public function test_the_same_configuration_spelled_two_ways_is_one_variant(): void
    {
        // Specification text comes from an AI, so the same machine arrives as
        // "16 GB" one day and "16 ГБ" the next. Hashed raw, that published one
        // product with two variants of a configuration that never differed.
        $english = $this->draft('Lenovo IdeaPad Slim 3', 'IdeaPad Slim 3', 'laptop');
        $russian = $this->draft('Lenovo IdeaPad Slim 3', 'IdeaPad Slim 3', 'laptop');
        $russian->update(['specifications' => [
            ['key' => 'cpu', 'name' => 'CPU', 'value' => 'AMD Ryzen 5'],
            ['key' => 'ram', 'name' => 'RAM', 'value' => '16 ГБ'],
            ['key' => 'storage', 'name' => 'SSD', 'value' => '512ГБ'],
        ]]);

        $workflow = app(ProductDraftWorkflow::class);
        $product = $workflow->approve($english);
        $workflow->approve($russian->refresh());

        $this->assertSame(1, $product->refresh()->variants()->count());
    }

    public function test_a_genuinely_different_configuration_stays_its_own_variant(): void
    {
        // The other direction: folding how text is written must not fold the
        // machines themselves together.
        $small = $this->draft('Lenovo IdeaPad Slim 3', 'IdeaPad Slim 3', 'laptop');
        $large = $this->draft('Lenovo IdeaPad Slim 3', 'IdeaPad Slim 3', 'laptop');
        $large->update(['specifications' => [
            ['key' => 'cpu', 'name' => 'CPU', 'value' => 'AMD Ryzen 5'],
            ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB'],
            ['key' => 'storage', 'name' => 'SSD', 'value' => '512 GB'],
        ]]);

        $workflow = app(ProductDraftWorkflow::class);
        $product = $workflow->approve($small);
        $workflow->approve($large->refresh());

        $this->assertSame(2, $product->refresh()->variants()->count());
    }

    private function draft(string $title, string $model, ?string $productType, ?string $category = null): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => $title,
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => $title,
            'started_at' => now(),
        ]);

        Queue::fake([StoreProductImages::class]);

        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => $title,
            'brand' => 'Lenovo',
            'model' => $model,
            'product_type' => $productType,
            'category' => $category,
            'description' => 'Описание товара.',
            'specifications' => [
                ['key' => 'cpu', 'name' => 'CPU', 'value' => 'AMD Ryzen 5'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '16 GB'],
                ['key' => 'storage', 'name' => 'SSD', 'value' => '512 GB'],
            ],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product']],
            'image_urls' => [],
            'confidence' => 0.9,
        ]);
        $this->addMedia($draft, 800, 800, 'verified');

        return $draft;
    }

    private function addMedia(
        ProductDraft $draft,
        int $width,
        int $height,
        string $verificationStatus,
    ): void {
        $draft->media()->create([
            'disk' => 'public',
            'path' => 'drafts/'.$draft->id.'/test-'.$width.'x'.$height.'.webp',
            'source_url' => 'https://example.com/test-'.$width.'x'.$height.'.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => $width,
            'height' => $height,
            'file_size' => 1024,
            'checksum' => hash('sha256', $draft->id.':'.$width.':'.$height.':'.$verificationStatus),
            'verification_status' => $verificationStatus,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
    }
}
