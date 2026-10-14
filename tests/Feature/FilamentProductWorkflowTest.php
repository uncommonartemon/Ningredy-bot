<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Filament\Support\PastedProductAttributes;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductCommercialEditor;
use App\Services\Products\ProductMediaFormData;
use App\Services\Products\ProductMediaUploader;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentProductWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_create_another_retains_category_and_brand_without_reusing_offer_or_photo_data(): void
    {
        $brand = Brand::create(['name' => 'Workflow brand']);
        $category = Category::where('slug', 'laptops')->sole();
        $page = Livewire::test(CreateProduct::class)->set('data.category_id', $category->id);
        $key = array_key_first($page->get('data.variants'));
        $page->fillForm(['title' => 'First workflow product', 'brand_id' => $brand->id, 'status' => 'draft',
            'new_photos' => [UploadedFile::fake()->image('first.jpg')]])
            ->set("data.variants.$key.sku", 'FIRST-WORKFLOW')
            ->set("data.variants.$key.price", 123)
            ->set("data.variants.$key.quantity", 7)
            ->call('create', true)->assertHasNoFormErrors();
        $this->assertEquals($category->id, $page->get('data.category_id'));
        $this->assertEquals($brand->id, $page->get('data.brand_id'));
        $this->assertEmpty($page->get('data.title'));
        $this->assertEmpty($page->get('data.new_photos'));
        $this->assertSame('published', $page->get('data.status'));
        $variants = $page->get('data.variants');
        $this->assertCount(1, $variants);
        $this->assertArrayNotHasKey($key, $variants);
        $variant = array_values($variants)[0];
        $this->assertEmpty($variant['sku'] ?? null);
        $this->assertEmpty($variant['price'] ?? null);
        $this->assertEquals(0, $variant['quantity']);
        $this->assertTrue($variant['is_default']);
        $this->assertCount(3, $variant['attributes']);
        $page->fillForm(['title' => 'Second workflow product'])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Product::where('title', 'First workflow product')->sole()->media()->count());
        $second = Product::where('title', 'Second workflow product')->sole();
        $this->assertTrue(Product::visibleInCatalog()->whereKey($second->id)->exists());
        $this->assertSame('draft', Product::where('title', 'First workflow product')->sole()->status);
        $this->assertSame(0, $second->media()->count());
        $this->assertNull($second->variants()->sole()->price);
    }

    public function test_copying_a_saved_variant_creates_independent_specs_without_inventory_or_identifiers(): void
    {
        $product = $this->product();
        $original = $product->variants()->create($this->offer(['sku' => 'ORIGINAL-SKU', 'mpn' => 'ORIGINAL-MPN',
            'gtin' => '123456', 'quantity' => 5, 'price' => 120, 'name' => 'Same chassis', 'color' => 'Black']));
        $attribute = $original->attributes()->create(['attribute_definition_id' => AttributeDefinition::where('key', 'ram')->value('id'),
            'value' => '16', 'unit' => 'GB']);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $key = array_key_first($page->get('data.variants'));
        $page->callAction(TestAction::make('clone')->schemaComponent('variants')->arguments(['item' => $key]))
            ->assertHasNoActionErrors();
        $copiedState = array_values($page->get('data.variants'))[1];
        $this->assertArrayHasKey('description', $copiedState['translations']['cs']);
        $this->assertNull($copiedState['translations']['cs']['description']);
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $product->variants()->count());
        $copy = $product->variants()->whereKeyNot($original->id)->sole();
        $this->assertNull($copy->sku);
        $this->assertNull($copy->mpn);
        $this->assertNull($copy->gtin);
        $this->assertNull($copy->price);
        $this->assertSame(0, $copy->quantity);
        $this->assertFalse($copy->is_default);
        $this->assertSame('Black', $copy->color);
        $this->assertSame('16', $copy->attributes()->sole()->value);
        $this->assertNotSame($attribute->id, $copy->attributes()->sole()->id);
        $this->assertSame('ORIGINAL-SKU', $original->fresh()->sku);
        $this->assertSame(1, $product->variants()->where('is_default', true)->count());
    }

    public function test_switching_and_deleting_primary_offer_keeps_exactly_one_primary(): void
    {
        $product = $this->product();
        $first = $product->variants()->create($this->offer());
        $second = $product->variants()->create($this->offer(['is_default' => false]));
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $secondKey = 'record-'.$second->id;
        $page->set("data.variants.$secondKey.is_default", true)->call('save')->assertHasNoFormErrors();
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $page->callAction(TestAction::make('delete')->schemaComponent('variants')->arguments(['item' => $secondKey]))
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($first->id, $product->variants()->sole()->id);
        $this->assertTrue($first->fresh()->is_default);
    }

    public function test_legacy_product_without_offers_can_still_be_edited(): void
    {
        $product = $this->product();
        Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['title' => 'Legacy renamed'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Legacy renamed', $product->fresh()->title);
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_two_column_paste_preserves_units_and_explicit_empty_third_column_clears_them(): void
    {
        $ram = AttributeDefinition::where('key', 'ram')->sole();
        $paste = app(PastedProductAttributes::class);
        $rows = ['row' => ['attribute_definition_id' => $ram->id, 'value' => null, 'unit' => 'GiB']];
        $result = $paste->merge("ram\t32", $rows);
        $this->assertSame('GiB', $result['rows']['row']['unit']);
        $result = $paste->merge("ram\t64\t", $result['rows'], replaceFilled: true);
        $this->assertSame('', $result['rows']['row']['unit']);
    }

    public function test_selecting_first_attribute_preserves_pretyped_value_and_custom_unit(): void
    {
        $page = Livewire::test(CreateProduct::class);
        $key = array_key_first($page->get('data.variants'));
        $page->set("data.variants.$key.attributes", ['row' => ['value' => '32', 'unit' => 'GiB']])
            ->set("data.variants.$key.attributes.row.attribute_definition_id", AttributeDefinition::where('key', 'ram')->value('id'));
        $this->assertSame('32', $page->get("data.variants.$key.attributes.row.value"));
        $this->assertSame('GiB', $page->get("data.variants.$key.attributes.row.unit"));
    }

    public function test_quick_commercial_action_saves_only_the_chosen_variant(): void
    {
        $product = $this->product();
        $first = $product->variants()->create($this->offer(['price' => 100]));
        $second = $product->variants()->create($this->offer(['is_default' => false, 'price' => 200, 'sku' => 'KEEP-SKU']));
        $page = Livewire::test(ListProducts::class)->mountAction(TestAction::make('editCommercial')->table($product));
        $page->set('mountedActions.0.data.variant_id', $second->id);
        $this->assertEquals(200, $page->get('mountedActions.0.data.price'));
        $page->fillForm(['price' => null, 'currency' => 'eur', 'quantity' => 3, 'stock_status' => 'in_stock', 'warranty_months' => 12])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('100.00', $first->fresh()->price);
        $this->assertNull($second->fresh()->price);
        $this->assertSame('EUR', $second->fresh()->currency);
        $this->assertSame(3, $second->fresh()->quantity);
        $this->assertSame('KEEP-SKU', $second->fresh()->sku);
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_quick_commercial_action_rejects_stale_data_without_overwriting_newer_values(): void
    {
        $product = $this->product();
        $variant = $product->variants()->create($this->offer(['price' => 100]));
        $page = Livewire::test(ListProducts::class)->mountAction(TestAction::make('editCommercial')->table($product));
        $variant->update(['price' => 150]);
        $page->callMountedAction()->assertHasActionErrors(['price']);
        $this->assertSame('150.00', $variant->fresh()->price);
    }

    public function test_quick_commercial_service_rejects_another_products_variant(): void
    {
        $product = $this->product();
        $other = $this->product('Other product');
        $variant = $other->variants()->create($this->offer(['price' => 100]));
        $data = app(ProductCommercialEditor::class)->values($other);
        try {
            app(ProductCommercialEditor::class)->save($product, [...$data, 'price' => 200]);
            $this->fail('Foreign variant must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variant_id', $exception->errors());
        }
        $this->assertSame('100.00', $variant->fresh()->price);
    }

    public function test_cancelling_quick_edit_keeps_inventory_and_legacy_products_have_no_quick_action(): void
    {
        $product = $this->product();
        $variant = $product->variants()->create($this->offer(['price' => 100, 'quantity' => 4]));
        $legacy = $this->product('Legacy without an offer');
        Livewire::test(ListProducts::class)
            ->assertActionDisabled(TestAction::make('editCommercial')->table($legacy))
            ->mountAction(TestAction::make('editCommercial')->table($product))
            ->fillForm(['price' => 900, 'quantity' => 20])
            ->unmountAction();
        $this->assertSame('100.00', $variant->fresh()->price);
        $this->assertSame(4, $variant->fresh()->quantity);
    }

    public function test_gallery_edit_of_local_photo_alt_does_not_validate_storage_url_or_drop_verification(): void
    {
        $product = $this->product();
        app(ProductMediaUploader::class)->upload($product, [UploadedFile::fake()->image('local.jpg')]);
        $photo = $product->media()->sole();
        $photo->update(['verification_status' => 'verified', 'verification_model' => 'test-model']);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callAction(TestAction::make('edit')->table($photo), data: ['alt' => 'New caption'])
            ->assertHasNoActionErrors();
        $this->assertSame('New caption', $photo->fresh()->alt);
        $this->assertSame('verified', $photo->fresh()->verification_status);
        $this->assertSame('test-model', $photo->fresh()->verification_model);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_changing_photo_source_or_variant_clears_old_proof_but_retains_actual_file_metadata(): void
    {
        $product = $this->product();
        $first = $product->variants()->create($this->offer());
        $second = $product->variants()->create($this->offer(['is_default' => false]));
        app(ProductMediaUploader::class)->upload($product, [UploadedFile::fake()->image('local.jpg', 640, 480)]);
        $photo = $product->media()->sole();
        $photo->update(['verification_status' => 'verified', 'verification_model' => 'test-model', 'verification_score' => .99]);
        $helper = app(ProductMediaFormData::class);
        $alt = $helper->prepare($product, ['alt' => 'Only caption'], $photo);
        $this->assertArrayNotHasKey('verification_status', $alt);
        $this->assertEquals($first->id, $alt['product_variant_id']);
        foreach ([['source_url' => 'https://example.com/changed'], ['product_variant_id' => $second->id]] as $change) {
            $data = $helper->prepare($product, $change, $photo);
            $this->assertSame('manual', $data['verification_status']);
            $this->assertNull($data['verification_model']);
            $this->assertNull($data['verification_score']);
            $this->assertSame(640, $data['width']);
            $this->assertSame(480, $data['height']);
            $this->assertSame($photo->checksum, $data['checksum']);
        }
    }

    public function test_replacing_a_gallery_file_recalculates_dimensions_and_drops_previous_verification(): void
    {
        $product = $this->product();
        app(ProductMediaUploader::class)->upload($product, [UploadedFile::fake()->image('before.jpg', 640, 480)]);
        $photo = $product->media()->sole();
        $oldPath = $photo->path;
        $oldChecksum = $photo->checksum;
        $photo->update(['verification_status' => 'verified', 'verification_model' => 'old-vision', 'verification_score' => .99]);
        Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->mountAction(TestAction::make('edit')->table($photo))
            ->fillForm(['path' => []])
            ->fillForm(['path' => [UploadedFile::fake()->image('after.png', 800, 600)]])
            ->callMountedAction()->assertStatus(200)
            ->assertHasNoActionErrors();
        $photo->refresh();
        $this->assertNotSame($oldPath, $photo->path);
        $this->assertNotSame($oldChecksum, $photo->checksum);
        $this->assertSame(800, $photo->width);
        $this->assertSame(600, $photo->height);
        $this->assertSame('image/png', $photo->mime_type);
        $this->assertSame('manual', $photo->verification_status);
        $this->assertNull($photo->verification_model);
        $this->assertNull($photo->verification_score);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_gallery_bulk_upload_binds_selected_variant_or_shared_gallery(): void
    {
        $product = $this->product();
        $first = $product->variants()->create($this->offer());
        $second = $product->variants()->create($this->offer(['is_default' => false]));
        foreach ([$first->id, $second->id, null] as $variantId) {
            Livewire::test(MediaRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
                ->callAction(TestAction::make('uploadPhotos')->table(), data: ['product_variant_id' => $variantId,
                    'new_photos' => [UploadedFile::fake()->image('gallery.jpg')]])->assertHasNoActionErrors();
            $this->assertTrue($product->media()->where('product_variant_id', $variantId)->sole()->is_primary);
        }
        $this->assertSame(3, $product->media()->count());
    }

    public function test_upload_to_foreign_variant_is_rejected_before_storing_files(): void
    {
        $product = $this->product();
        $other = $this->product('Foreign owner');
        $variant = $other->variants()->create($this->offer());
        try {
            app(ProductMediaUploader::class)->upload($product, [UploadedFile::fake()->image('foreign.jpg')], $variant->id);
            $this->fail('Foreign variant must not receive this product image.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_variant_id', $exception->errors());
        }
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
        $this->assertSame(0, $product->media()->count());
    }

    private function product(string $title = 'Workflow product'): Product
    {
        return Product::create(['title' => $title, 'category_id' => Category::where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft', 'is_active' => true]);
    }

    private function offer(array $overrides = []): array
    {
        return [...['condition' => 'new', 'currency' => 'CZK', 'quantity' => 0,
            'stock_status' => 'unknown', 'is_default' => true, 'is_active' => true], ...$overrides];
    }
}
