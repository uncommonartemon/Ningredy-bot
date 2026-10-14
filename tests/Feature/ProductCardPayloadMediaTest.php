<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductCardPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductCardPayloadMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_default_variant_keeps_price_and_cover_on_the_same_variant_without_extra_card_queries(): void
    {
        Queue::fake();
        $products = [];
        foreach (['Laptop one', 'Laptop two'] as $title) {
            $product = $this->product($title);
            $previous = $product->variants()->create(['name' => 'Previous', 'price' => 100, 'is_default' => false, 'is_active' => true]);
            $selected = $product->variants()->create(['name' => 'Selected', 'price' => 200, 'is_default' => true, 'is_active' => true]);
            $this->media($product, $previous, 'previous.jpg', primary: true);
            $this->media($product, null, 'shared.jpg', primary: true);
            $this->media($product, $selected, 'selected.jpg', primary: false);
            $this->media($product, $selected, 'manual.pdf', primary: true, type: 'document');
            $products[] = $product;
        }
        $loaded = Product::query()->whereKey(collect($products)->pluck('id'))->with([
            'brand', 'category.translations', 'variants.attributes.definition',
            'catalogMedia:id,product_id,product_variant_id,disk,path,url',
        ])->orderBy('id')->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ($loaded as $product) {
                $card = app(ProductCardPayload::class)->for($product);
                $this->assertSame(200.0, $card['price']);
                $this->assertSame('/storage/products/'.$product->id.'/selected.jpg', $card['image']);
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_cover_uses_shared_photo_when_selected_variant_has_no_approved_image(): void
    {
        $product = $this->product('Shared cover');
        $other = $product->variants()->create(['is_active' => true, 'is_default' => false]);
        $selected = $product->variants()->create(['is_active' => true, 'is_default' => true]);
        $this->media($product, $other, 'other.jpg', primary: true);
        $this->media($product, $selected, 'rejected.jpg', primary: true, verification: 'rejected');
        $this->media($product, null, 'shared.jpg', primary: false);

        $this->assertSame('/storage/products/'.$product->id.'/shared.jpg',
            app(ProductCardPayload::class)->for($product->fresh())['image']);
    }

    public function test_inactive_default_variant_cannot_supply_the_price_or_cover_of_the_available_variant(): void
    {
        $product = $this->product('Inactive default');
        $hidden = $product->variants()->create(['price' => 100, 'is_active' => false, 'is_default' => true]);
        $available = $product->variants()->create(['price' => 300, 'is_active' => true, 'is_default' => false]);
        $this->media($product, $hidden, 'hidden.jpg', primary: true);
        $this->media($product, $available, 'available.jpg', primary: false);

        $card = app(ProductCardPayload::class)->for($product->fresh());
        $this->assertSame(300.0, $card['price']);
        $this->assertSame('/storage/products/'.$product->id.'/available.jpg', $card['image']);
        $product->media()->where('product_variant_id', $available->id)->delete();
        $this->assertNull(app(ProductCardPayload::class)->for($product->fresh())['image']);
    }

    private function product(string $title): Product
    {
        return Product::query()->create(['title' => $title, 'product_type' => 'laptop', 'status' => 'published',
            'category_id' => Category::where('slug', 'laptops')->value('id'), 'is_active' => true]);
    }

    private function media(Product $product, ?ProductVariant $variant, string $filename, bool $primary,
        string $type = 'image', string $verification = 'manual'): ProductMedia
    {
        return $product->media()->create(['product_variant_id' => $variant?->id, 'type' => $type,
            'disk' => 'public', 'path' => 'products/'.$product->id.'/'.$filename,
            'verification_status' => $verification, 'is_primary' => $primary, 'sort_order' => 0]);
    }
}
