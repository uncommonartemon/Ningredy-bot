<?php

namespace Tests\Feature;

use App\Filament\Pages\BotStatus;
use App\Filament\Pages\TelegramSettings;
use App\Filament\Resources\ProductGalleryRecipes\Pages\EditProductGalleryRecipe;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGalleryRecipe;
use App\Models\User;
use App\Services\BotHealth;
use App\Services\Products\ProductMediaUploader;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentNativeFormsTest extends TestCase
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

    public function test_product_creation_saves_multiple_uploaded_photos_with_real_metadata(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            ...$this->productData(),
            'variants' => [], 'sources' => [],
            'new_photos' => [UploadedFile::fake()->image('front.jpg', 1200, 800), UploadedFile::fake()->image('side.png', 900, 600)],
        ])->call('create')->assertHasNoFormErrors();

        $product = Product::query()->where('title', 'Manual laptop')->sole();
        $photos = $product->media()->get();
        $this->assertCount(2, $photos);
        $this->assertTrue($photos[0]->is_primary);
        $this->assertFalse($photos[1]->is_primary);
        $this->assertSame([0, 1], $photos->pluck('sort_order')->all());
        $this->assertSame([1200, 900], $photos->pluck('width')->all());
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
            $this->assertStringStartsWith("products/{$product->id}/", $photo->path);
            $this->assertSame('manual', $photo->verification_status);
            $this->assertNotEmpty($photo->checksum);
        }
    }

    public function test_edit_adds_new_photos_without_replacing_existing_files_or_uploading_twice(): void
    {
        $product = Product::query()->create($this->productData());
        app(ProductMediaUploader::class)->upload($product, [UploadedFile::fake()->image('original.jpg')]);
        $original = $product->media()->sole();
        $page = Livewire::test(EditProduct::class, ['record' => $product->getKey()])->fillForm([
            'new_photos' => [UploadedFile::fake()->image('another.jpg', 1100, 700)],
        ])->call('save')->assertHasNoFormErrors();
        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame(2, $product->media()->count());
        $this->assertSame($original->id, $product->media()->where('is_primary', true)->sole()->id);
        Storage::disk('public')->assertExists($original->path);
        $this->assertSame([0, 1], $product->media()->get()->pluck('sort_order')->all());
    }

    public function test_gallery_bulk_upload_action_saves_all_photos_and_notifies(): void
    {
        $product = Product::query()->create($this->productData());
        Livewire::test(MediaRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ])->callAction(TestAction::make('uploadPhotos')->table(), data: [
            'new_photos' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')],
        ])->assertHasNoActionErrors()->assertNotified('Фотографии добавлены');

        $this->assertSame(2, $product->media()->count());
        $this->assertSame(1, $product->media()->where('is_primary', true)->count());
    }

    public function test_single_photo_form_uses_image_content_for_the_stored_extension(): void
    {
        $product = Product::query()->create($this->productData());
        $image = UploadedFile::fake()->image('actual.jpg');
        $upload = (new File('misleading.png', $image->tempFile))
            ->mimeType(mime_content_type($image->getRealPath()));
        Livewire::test(MediaRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class,
        ])->callAction(TestAction::make('create')->table(), data: [
            'path' => $upload,
            'role' => 'secondary', 'verification_status' => 'manual', 'sort_order' => 0,
        ])->assertHasNoActionErrors();

        $photo = $product->media()->sole();
        $this->assertSame('jpg', pathinfo($photo->path, PATHINFO_EXTENSION));
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_non_image_upload_is_rejected_before_creating_a_product(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            ...$this->productData(), 'variants' => [], 'sources' => [],
            'new_photos' => [UploadedFile::fake()->create('script.html', 1, 'text/html')],
        ])->call('create')->assertHasFormErrors();
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_upload_service_rejects_a_submitted_existing_file_path(): void
    {
        $product = Product::query()->create($this->productData());
        Storage::disk('public')->put('products/other.jpg', 'unrelated');
        try {
            app(ProductMediaUploader::class)->upload($product, ['products/other.jpg']);
            $this->fail('A disk path must not be accepted as an upload.');
        } catch (ValidationException) {
            Storage::disk('public')->assertExists('products/other.jpg');
            $this->assertSame(0, $product->media()->count());
        }
    }

    public function test_native_settings_and_health_pages_render_without_custom_blade_templates(): void
    {
        $this->mock(BotHealth::class)->shouldReceive('checks')->andReturn([
            ['key' => 'worker', 'label' => 'Очередь', 'state' => 'up', 'detail' => 'Есть heartbeat', 'hint' => ''],
        ]);
        $this->get('/admin/ai-settings-page')->assertOk()->assertSee('Сохранить настройки AI');
        $this->get('/admin/telegram-settings')->assertOk()->assertSee('Сохранить настройки');
        Livewire::test(BotStatus::class)->assertSee('Есть heartbeat')->set('smokeOutput', '<script>alert(1)</script>')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_telegram_native_form_saves_without_registering_a_webhook(): void
    {
        Livewire::test(TelegramSettings::class)->fillForm([
            'proxy_url' => 'https://example.com', 'allowed_user_ids' => "123\n456",
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame('https://example.com', AppSetting::valueFor(AppSetting::TELEGRAM_PROXY_URL));
        $this->assertSame("123\n456", AppSetting::valueFor(AppSetting::TELEGRAM_ALLOWED_USER_IDS));
        Http::assertNothingSent();
    }

    public function test_recipe_form_allows_two_paths_of_one_domain_but_rejects_an_exact_duplicate(): void
    {
        ProductGalleryRecipe::query()->create(['domain' => 'shop.example', 'path_pattern' => '/a/*', 'status' => 'learning']);
        $recipe = ProductGalleryRecipe::query()->create(['domain' => 'shop.example', 'path_pattern' => '/b/*', 'status' => 'learning']);
        Livewire::test(EditProductGalleryRecipe::class, ['record' => $recipe->id])
            ->call('save')->assertHasNoFormErrors()
            ->fillForm(['path_pattern' => '/a/*'])->call('save')->assertHasFormErrors(['path_pattern' => 'unique']);
    }

    private function productData(): array
    {
        return ['title' => 'Manual laptop', 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'draft', 'sort_order' => 0, 'is_active' => true];
    }
}
