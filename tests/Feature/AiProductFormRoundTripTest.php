<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductDrafts\Pages\EditProductDraft;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Products\ProductCondition;
use App\Services\Products\ProductSourceTypes;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiProductFormRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_all_research_source_types_can_be_saved_unchanged_in_catalog(): void
    {
        $product = Product::create([
            'title' => 'AI laptop', 'product_type' => 'laptop', 'status' => 'published',
            'category_id' => Category::where('slug', 'laptops')->value('id'),
        ]);
        $variant = $product->variants()->create([
            'sku' => 'AI-SKU-123', 'gtin' => '1234567890123', 'condition' => 'new',
            'currency' => 'CZK', 'stock_status' => 'unknown', 'is_default' => true,
        ]);
        foreach (ProductSourceTypes::VALUES as $type) {
            $product->sources()->create([
                'product_variant_id' => $variant->id, 'title' => str_repeat($type, 32),
                'url' => "https://example.com/$type", 'domain' => 'example.com',
                'source_type' => $type, 'confidence' => 0.91,
            ]);
        }
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(ProductSourceTypes::VALUES, $product->sources()->orderBy('id')->pluck('source_type')->all());
        $this->assertSame('AI-SKU-123', $variant->fresh()->sku);
        $this->assertSame('1234567890123', $variant->fresh()->gtin);
        $this->assertSame(6, $product->sources()->where('confidence', 0.91)->count());
    }

    public function test_same_sku_in_different_conditions_and_translations_save_unchanged(): void
    {
        $translations = ['cs' => ['title' => 'Notebook', 'description' => 'Popis notebooku']];
        $product = Product::create(['title' => 'Laptop', 'product_type' => 'laptop', 'status' => 'draft', 'translations' => $translations,
            'category_id' => Category::where('slug', 'laptops')->value('id')]);
        foreach (ProductCondition::VALUES as $condition) {
            $product->variants()->create(['sku' => 'SAME-SKU', 'condition' => $condition, 'currency' => 'CZK',
                'stock_status' => 'unknown', 'is_default' => $condition === 'new', 'translations' => $translations]);
        }
        Livewire::test(EditProduct::class, ['record' => $product->id])->call('save')->assertHasNoFormErrors();
        $this->assertCount(4, $product->variants()->get());
        $this->assertSame($translations['cs'], $product->fresh()->translations['cs']);
        Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['title' => 'Manually renamed laptop'])
            ->call('save')->assertHasNoFormErrors();
        // Unchanged base fields retain translations; the renamed English
        // title no longer leaves its old Czech title visible in the catalog.
        $this->assertSame(['description' => $translations['cs']['description']], $product->fresh()->translations['cs']);
        foreach ($product->variants()->get() as $variant) {
            $this->assertSame('SAME-SKU', $variant->sku);
            $this->assertSame($translations['cs'], $variant->translations['cs']);
        }
    }

    public function test_draft_round_trip_preserves_ai_source_and_specification_metadata(): void
    {
        $sources = array_map(fn ($type) => [
            'title' => $type, 'url' => "https://example.com/$type", 'type' => $type,
            'identity_evidence' => ['sku' => 'AI-SKU-123'],
        ], ProductSourceTypes::VALUES);
        $specifications = [['name' => 'Memory', 'value' => '16 GB', 'key' => 'installed_ram', 'unit' => 'GB']];
        $update = TelegramUpdate::create(['update_id' => 987, 'payload' => []]);
        $run = AiRun::create([
            'telegram_update_id' => $update->id, 'provider' => 'test', 'model' => 'fake',
            'prompt' => 'test', 'started_at' => now(),
        ]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'title' => 'AI draft', 'product_type' => 'laptop', 'status' => 'pending_review',
            'requested_by_telegram_user_id' => '123', 'image_urls' => [],
            'sources' => $sources, 'specifications' => $specifications,
        ]);
        Livewire::test(EditProductDraft::class, ['record' => $draft->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($sources, $draft->fresh()->sources);
        $this->assertSame($specifications, $draft->fresh()->specifications);
    }
}
