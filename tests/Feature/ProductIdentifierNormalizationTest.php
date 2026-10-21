<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductIdentifierNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_publishing_keeps_open_box_translations_and_new_fact_sources(): void
    {
        $translations = ['cs' => ['title' => 'Notebook', 'description' => 'Ověřené parametry.']];
        $draft = $this->draftWithIdentifiers([
            ['key' => 'sku', 'name' => 'SKU', 'value' => 'SAME-123'],
            ['key' => 'video_memory', 'name' => 'VRAM', 'value' => '8 GB', 'source_url' => 'https://maker.example/specs', 'source_excerpt' => '8 GB VRAM'],
            ['key' => 'maximum_memory', 'name' => 'Maximum RAM', 'value' => '64 GB'],
        ], ['condition' => 'open_box', 'translations' => $translations]);
        $product = app(ProductDraftWorkflow::class)->approve($draft);
        $variant = $product->variants()->firstOrFail();
        $this->assertSame('open_box', $variant->condition);
        $this->assertSame($translations, $variant->translations);
        $this->assertSame($translations, $product->translations);
        $this->assertDatabaseHas('product_sources', ['product_variant_id' => $variant->id, 'url' => 'https://maker.example/specs']);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'video_memory']);
        $this->assertDatabaseHas('attribute_definitions', ['key' => 'maximum_memory']);
        $this->assertDatabaseMissing('attribute_definitions', ['key' => 'video_memory', 'label' => 'Оперативная память']);
        $newDraft = $this->draftWithIdentifiers([['key' => 'sku', 'name' => 'SKU', 'value' => 'SAME-123']]);
        $second = app(ProductDraftWorkflow::class)->approve($newDraft);
        $this->assertSame($product->id, $second->id);
        $this->assertSame(['new', 'open_box'], $product->variants()->orderBy('condition')->pluck('condition')->all());
    }

    public function test_different_mpns_of_a_shared_model_cannot_overwrite_the_existing_product(): void
    {
        $firstDraft = $this->draftWithIdentifiers([
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'FAMILY-2TB'],
            ['key' => 'storage', 'name' => 'Storage', 'value' => '2 TB'],
        ]);
        $first = app(ProductDraftWorkflow::class)->approve($firstDraft);
        $before = $first->fresh()->getAttributes();
        $secondDraft = $this->draftWithIdentifiers([
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'FAMILY-1TB'],
            ['key' => 'storage', 'name' => 'Storage', 'value' => '1 TB'],
        ], ['title' => 'A different sellable configuration']);
        $second = app(ProductDraftWorkflow::class)->approve($secondDraft);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertSame('FAMILY-2TB', $first->variants()->sole()->mpn);
        $this->assertSame('FAMILY-1TB', $second->variants()->sole()->mpn);
    }

    public function test_a_card_naming_the_same_item_by_another_code_joins_it_and_keeps_both_codes(): void
    {
        // Live #567: the second card for the same ASUS GPU carried only its
        // model code and became a second product next to the first.
        $first = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers(
            [['key' => 'mpn', 'name' => 'MPN', 'value' => '90YV0JC0-M0NA00']],
            ['model' => 'DUAL-RTX4060-O8G', 'title' => 'ASUS Dual GeForce RTX 4060 OC Edition 8GB GDDR6'],
        ));
        $second = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers(
            [['key' => 'sku', 'name' => 'SKU', 'value' => 'DUAL-RTX4060-O8G']],
            ['model' => 'DUAL-RTX4060-O8G', 'title' => 'ASUS Dual GeForce RTX 4060 OC Edition 8GB'],
        ));

        $this->assertSame($first->id, $second->id);
        $variant = $first->variants()->sole();
        $this->assertSame(['DUAL-RTX4060-O8G', '90YV0JC0-M0NA00'], [$variant->sku, $variant->mpn]);
        // Codes stay on the product page but are not storefront filters.
        $this->assertSame([false, false], AttributeDefinition::whereIn('key', ['mpn', 'sku'])->orderBy('key')->pluck('is_filterable')->all());
    }

    public function test_an_identifier_arriving_with_its_explanation_is_stored_clean(): void
    {
        // Seen live on a published MacBook Air: research wrote the part number
        // for a human reader, and the whole sentence landed in the sku column.
        // Such a value can never match the same product's plain MC7A4LL/A from
        // another source, which is exactly what duplicate detection compares.
        $product = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers([
            ['key' => 'sku', 'name' => 'Retailer SKU', 'value' => 'MC7A4LL/A (US retailer / MFG part number for 15-inch M4 — 16 GB / 256 GB — Sky Blue)'],
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'XPS9350-5342GLD'],
        ]));

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('MC7A4LL/A', $variant->sku);
        // A hyphen inside a token is part of the identifier, never a separator.
        $this->assertSame('XPS9350-5342GLD', $variant->mpn);
    }

    public function test_every_prose_separator_is_cut_without_touching_real_identifiers(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers([
            ['key' => 'sku', 'name' => 'SKU', 'value' => 'SFG14-73-79D3, Silver'],
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'NX.KSGEK.002 — UK variant'],
            ['key' => 'gtin', 'name' => 'GTIN', 'value' => '4711121557880 [EAN-13]'],
        ]));

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('SFG14-73-79D3', $variant->sku);
        $this->assertSame('NX.KSGEK.002', $variant->mpn);
        $this->assertSame('4711121557880', $variant->gtin);
    }

    /** @param array<int, array<string, string>> $specifications */
    private function draftWithIdentifiers(array $specifications, array $overrides = []): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'Apple MacBook Air 15 M4',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Apple MacBook Air 15 M4',
            'started_at' => now(),
        ]);

        Queue::fake([StoreProductImages::class]);

        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Apple MacBook Air (15-inch, M4)',
            'brand' => 'Apple',
            'model' => 'MacBook Air 15 M4',
            'product_type' => 'laptop',
            'category' => 'laptops',
            'color' => 'Sky Blue',
            'description' => 'Тонкий и лёгкий ноутбук.',
            'specifications' => $specifications,
            'sources' => [['title' => 'Fixture product source', 'url' => 'https://example.com/product']],
            'image_urls' => [],
            'confidence' => 0.9,
            'status' => 'pending_review',
            ...$overrides,
            // Identification/translation normalization is exercised after
            // successful assembly, not by bypassing its readiness guard.
            'primary_source_url' => 'https://example.com/product',
            'specifications_reconciled_source_url' => 'https://example.com/product',
        ]);
        // approve() refuses a draft with no media, so give it one that clears
        // the category's own resolution minimum.
        $path = 'drafts/'.$draft->id.'/photo.webp';
        $image = imagecreatetruecolor(1200, 1200);
        ob_start();
        imagewebp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put($path, $bytes);
        $draft->media()->create([
            'disk' => 'public',
            'path' => $path,
            'source_url' => 'https://example.com/photo.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => 1200,
            'height' => 1200,
            'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $draft;
    }
}
