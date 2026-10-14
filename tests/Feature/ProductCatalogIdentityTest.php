<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Products\ProductCatalogIdentity;
use App\Services\Products\ProductIdentityKey;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductCatalogIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function card(array $identifiers, ?string $model = 'Shared family'): array
    {
        return ['brand' => 'Example', 'model' => $model, 'title' => 'Example product',
            'specifications' => collect($identifiers)->map(fn ($value, $key) => compact('key', 'value'))->values()->all()];
    }

    private function product(array $identifiers, string $condition = 'new', bool $active = true): Product
    {
        $brand = Brand::firstOrCreate(['slug' => 'example'], ['name' => 'Example']);
        $product = Product::create([
            'brand_id' => $brand->id, 'category_id' => Category::where('slug', 'components')->value('id'),
            'canonical_key' => ProductIdentityKey::for('Example', 'Shared family', 'Example product'),
            'title' => 'Example product', 'model' => 'Shared family', 'product_type' => 'component',
            'status' => 'published', 'is_active' => true,
        ]);
        $product->variants()->create([...$identifiers, 'fingerprint' => 'test',
            'condition' => $condition, 'is_default' => true, 'is_active' => $active]);

        return $product;
    }

    public function test_existing_legacy_key_is_found_by_exact_identifiers_even_if_model_text_changes(): void
    {
        $product = $this->product(['mpn' => 'MODEL-123', 'gtin' => '1234567890123']);
        $card = $this->card(['mpn' => 'model-123 (regional retail package)', 'ean' => '1234567890123'], 'Different spelling');

        $this->assertSame($product->id, app(ProductCatalogIdentity::class)->find($card, 'new', true)?->id);
        $this->assertSame('example-shared-family', $product->fresh()->canonical_key);
    }

    public function test_equal_mpn_does_not_override_a_conflicting_sku(): void
    {
        $this->product(['mpn' => 'FAMILY', 'sku' => 'EXACT-A']);

        $this->assertNull(app(ProductCatalogIdentity::class)->find($this->card(['mpn' => 'FAMILY', 'sku' => 'EXACT-B']), 'new', true));
    }

    public function test_gtin_padding_does_not_make_the_same_trade_item_a_different_product(): void
    {
        $product = $this->product(['mpn' => 'EXACT-A', 'gtin' => '198156389541']);
        $identity = app(ProductCatalogIdentity::class);
        $upc = $this->card(['mpn' => 'EXACT-A', 'upc' => '198156389541']);
        foreach (['0198156389541', '00198156389541'] as $padded) {
            $card = $this->card(['mpn' => 'EXACT-A', 'gtin' => $padded]);
            $this->assertSame($product->id, $identity->find($card, 'new', true)?->id);
            $this->assertSame($identity->keyForNewProduct($upc), $identity->keyForNewProduct($card));
        }
        $this->assertNull($identity->find($this->card(['mpn' => 'EXACT-A', 'gtin' => '0198156389542']), 'new', true));
        $this->assertNull($identity->find($this->card(['mpn' => '0EXACT-A', 'gtin' => '0198156389541']), 'new', true));
    }

    public function test_code_prefix_and_missing_identifiers_are_not_confirmations(): void
    {
        $product = $this->product(['mpn' => 'MODEL-123X']);
        $identity = app(ProductCatalogIdentity::class);
        $this->assertNull($identity->find($this->card(['mpn' => 'MODEL-123']), 'new', true));
        $product->variants()->update(['mpn' => null]);
        $this->assertNull($identity->find($this->card(['mpn' => 'MODEL-123']), 'new', true));
    }

    public function test_meaningful_code_separators_are_not_erased_to_force_a_match(): void
    {
        $this->product(['mpn' => 'MODEL-123']);
        $identity = app(ProductCatalogIdentity::class);
        $this->assertNull($identity->find($this->card(['mpn' => 'MODEL/123']), 'new', true));
        $this->assertNotSame(
            $identity->keyForNewProduct($this->card(['mpn' => 'MODEL-123'])),
            $identity->keyForNewProduct($this->card(['mpn' => 'MODEL/123'])),
        );
    }

    public function test_condition_and_active_status_are_preserved_for_search_but_not_product_grouping(): void
    {
        $product = $this->product(['sku' => 'EXACT-A'], 'open_box', false);
        $identity = app(ProductCatalogIdentity::class);
        $card = $this->card(['sku' => 'EXACT-A']);
        $this->assertNull($identity->find($card, 'new', true));
        $this->assertNull($identity->find($card, 'open_box', true));
        $this->assertSame($product->id, $identity->find($card)?->id);
        $product->variants()->update(['is_active' => true]);
        $this->assertSame($product->id, $identity->find($card, 'open_box', true)?->id);
    }

    public function test_exact_nondefault_variant_is_returned_instead_of_the_default_sibling(): void
    {
        $product = $this->product(['mpn' => 'EXACT-2']);
        $wanted = $product->variants()->create(['mpn' => 'EXACT-1', 'fingerprint' => 'one',
            'condition' => 'new', 'is_default' => false, 'is_active' => true]);
        $identity = app(ProductCatalogIdentity::class);
        $card = $this->card(['mpn' => 'EXACT-1']);
        $match = $identity->find($card, 'new', true);

        $this->assertSame($product->id, $match?->id);
        $this->assertSame($wanted->id, $identity->variant($match, $card['specifications'], 'new', true)?->id);
    }

    public function test_specific_key_is_stable_and_keeps_conflicting_identifiers_separate(): void
    {
        $identity = app(ProductCatalogIdentity::class);
        $first = $identity->keyForNewProduct($this->card(['mpn' => 'Family', 'sku' => 'EXACT-1']));
        $same = $identity->keyForNewProduct($this->card(['sku' => 'exact-1', 'mpn' => 'family']));
        $different = $identity->keyForNewProduct($this->card(['mpn' => 'Family', 'sku' => 'EXACT-2']));

        $this->assertSame($first, $same);
        $this->assertNotSame($first, $different);
    }

    public function test_presenter_uses_the_exact_variant_for_both_caption_and_photos(): void
    {
        Storage::fake('public');
        $product = $this->product(['mpn' => 'EXACT-2']);
        $other = $product->variants()->sole();
        $wanted = $product->variants()->create(['mpn' => 'EXACT-1', 'fingerprint' => 'one',
            'condition' => 'new', 'is_default' => false, 'is_active' => true]);
        $definition = AttributeDefinition::firstOrCreate(['key' => 'storage'], ['label' => 'Storage', 'data_type' => 'text']);
        foreach ([$other, $wanted] as $variant) {
            $variant->attributes()->create(['attribute_definition_id' => $definition->id,
                'value' => $variant->id === $wanted->id ? '1 TB' : '2 TB']);
            $path = 'products/'.$product->id.'/'.$variant->id.'.webp';
            Storage::disk('public')->put($path, 'fixture bytes');
            $product->media()->create(['product_variant_id' => $variant->id, 'type' => 'image',
                'disk' => 'public', 'path' => $path, 'verification_status' => 'verified', 'is_primary' => true]);
        }
        $expectedPath = Storage::disk('public')->path('products/'.$product->id.'/'.$wanted->id.'.webp');
        $telegram = $this->mock(TelegramClient::class);
        $telegram->shouldReceive('sendMediaGroupFiles')->once()->withArgs(function ($chat, $paths, $caption) use ($expectedPath): bool {
            $this->assertSame([$expectedPath], $paths);
            $this->assertStringContainsString('1 TB', $caption);
            $this->assertStringNotContainsString('2 TB', $caption);

            return $chat === '123';
        });
        app(ProductCardPresenter::class)->send($telegram, '123', $product, condition: 'new', variantId: $wanted->id);
    }
}
