<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramDraftEditAgent;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramCatalogEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TelegramCatalogEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        TelegramDraftEditAgent::fake()->preventStrayPrompts();
        Queue::fake();
        Storage::fake('public');
    }

    public function test_catalog_edit_copies_the_selected_variant_and_owns_its_photos_without_changing_the_product(): void
    {
        [$product, $variant, $update] = $this->catalog();
        $before = $product->fresh()->getAttributes();
        $source = $product->media()->first();
        $bytes = Storage::disk('public')->get($source->path);

        $draft = app(TelegramCatalogEditor::class)->createDraft($product, $update);

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame('pending_review', $draft->status);
        $this->assertTrue($draft->isManualUpload());
        $this->assertSame('catalog_edit', $draft->manualIntake()['origin']);
        $this->assertSame('ready', $draft->manualIntake()['status']);
        $this->assertSame($product->id, $draft->manualIntake()['target_product_id']);
        $this->assertSame($variant->id, $draft->manualIntake()['target_variant_id']);
        $this->assertSame('10900.00', $draft->manualIntake()['commercial']['price_amount']);
        $this->assertSame('used', $draft->condition);
        $this->assertSame('Black', $draft->color);
        $this->assertSame('16 GB', collect($draft->specifications)->firstWhere('key', 'memory')['value']);
        $this->assertSame('Intel Core i5', collect($draft->specifications)->firstWhere('key', 'cpu')['value']);
        $this->assertSame('SAMPLE-14', collect($draft->specifications)->firstWhere('key', 'sku')['value']);
        $this->assertSame('Ukázkový notebook', $draft->translations['cs']['title']);
        $copy = $draft->media()->sole();
        $this->assertNotSame($source->path, $copy->path);
        $this->assertStringStartsWith("product-drafts/{$draft->id}/catalog-edit/", $copy->path);
        $this->assertSame($bytes, Storage::disk('public')->get($copy->path));
        $this->assertSame(hash('sha256', $bytes), $copy->checksum);
        $this->assertSame('manual', $copy->verification_status);
        $this->assertSame('local', AiRun::sole()->provider);
        $this->assertSame('catalog_snapshot', AiRun::sole()->model);
        $this->assertTrue(AiRun::sole()->response['no_provider_invocation']);
        $this->assertSame(0, array_sum(AiRun::sole()->usage));
        Http::assertNothingSent();

        $copy->delete();
        Storage::disk('public')->assertExists($source->path);
        $this->assertDatabaseCount('product_media', 1);
    }

    public function test_duplicate_delivery_of_the_same_edit_request_reuses_the_draft(): void
    {
        [$product, , $update] = $this->catalog();
        $editor = app(TelegramCatalogEditor::class);

        $first = $editor->createDraft($product, $update);
        $second = $editor->createDraft($product, $update);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('product_drafts', 1);
        $this->assertDatabaseCount('product_draft_media', 1);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_changed_catalog_data_invalidates_the_saved_target_fingerprint(): void
    {
        [$product, $variant, $update] = $this->catalog();
        $editor = app(TelegramCatalogEditor::class);
        $draft = $editor->createDraft($product, $update);
        $before = $draft->manualIntake()['catalog_fingerprint'];
        $this->assertSame($before, $editor->fingerprint($product, $variant));

        $variant->update(['price' => '9999.00']);

        $this->assertNotSame($before, $editor->fingerprint($product, $variant));
    }

    public function test_an_explicit_nondefault_variant_uses_its_own_commercial_terms_specs_and_photos(): void
    {
        [$product, , $update] = $this->catalog();
        $selected = $product->variants()->create(['name' => 'Silver', 'color' => 'Silver', 'condition' => 'new',
            'sku' => 'SAMPLE-SILVER', 'price' => '14900.00', 'currency' => 'CZK', 'is_default' => false, 'is_active' => true]);
        $memory = AttributeDefinition::query()->where('key', 'memory')->firstOrFail();
        $selected->attributes()->create(['attribute_definition_id' => $memory->id, 'value' => '32 GB']);
        $path = "products/{$product->id}/silver.jpg";
        Storage::disk('public')->put($path, $this->jpeg());
        $source = $product->media()->create(['product_variant_id' => $selected->id, 'type' => 'image', 'disk' => 'public', 'path' => $path,
            'width' => 1200, 'height' => 800, 'verification_status' => 'manual', 'is_primary' => true]);
        $editor = app(TelegramCatalogEditor::class);

        $draft = $editor->createDraft($product, $update, $selected->id);
        $second = $editor->createDraft($product, $update, $selected->id);

        $this->assertSame($selected->id, $draft->manualIntake()['target_variant_id']);
        $this->assertSame('14900.00', $draft->manualIntake()['commercial']['price_amount']);
        $this->assertSame('Silver', $draft->color);
        $this->assertSame('new', $draft->condition);
        $this->assertSame('32 GB', collect($draft->specifications)->firstWhere('key', 'memory')['value']);
        $this->assertSame('SAMPLE-SILVER', collect($draft->specifications)->firstWhere('key', 'sku')['value']);
        $this->assertSame($source->id, $draft->manualIntake()['uploaded_photos'][0]['catalog_media_id']);
        $this->assertSame(1, $draft->media()->count());
        $this->assertSame($draft->id, $second->id);
        $this->assertDatabaseCount('ai_runs', 1);
        $this->assertDatabaseCount('product_drafts', 1);
    }

    public function test_a_variant_from_another_product_cannot_create_an_edit_draft(): void
    {
        [$product, , $update] = $this->catalog();
        $foreign = Product::query()->create(['category_id' => $product->category_id, 'title' => 'Another product', 'product_type' => 'laptop']);
        $foreignVariant = $foreign->variants()->create(['name' => 'Other', 'is_default' => true, 'is_active' => true]);

        try {
            app(TelegramCatalogEditor::class)->createDraft($product, $update, $foreignVariant->id);
            $this->fail('A variant must belong to the selected product.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('не относится', $exception->getMessage());
        }
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertDatabaseCount('ai_runs', 0);
        $this->assertFalse((bool) data_get($update->fresh()->payload, '_manual_product_upload'));
    }

    public function test_the_same_update_cannot_switch_the_target_variant_of_an_existing_draft(): void
    {
        [$product, $firstVariant, $update] = $this->catalog();
        $other = $product->variants()->create(['name' => 'Other', 'is_default' => false, 'is_active' => true]);
        $editor = app(TelegramCatalogEditor::class);
        $draft = $editor->createDraft($product, $update, $firstVariant->id);

        try {
            $editor->createDraft($product, $update, $other->id);
            $this->fail('Duplicate delivery must retain the original target variant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('другому черновику', $exception->getMessage());
        }
        $this->assertSame($firstVariant->id, $draft->fresh()->manualIntake()['target_variant_id']);
        $this->assertDatabaseCount('product_drafts', 1);
        $this->assertDatabaseCount('product_draft_media', 1);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_missing_or_untrusted_files_are_not_promoted_to_ready_photos(): void
    {
        [$product, , $update] = $this->catalog();
        $source = $product->media()->first();
        $source->update(['verification_status' => 'pending']);

        $draft = app(TelegramCatalogEditor::class)->createDraft($product, $update);

        $this->assertSame(0, $draft->media()->count());
        $this->assertSame('needs_input', $draft->manualIntake()['status']);
        $this->assertContains('product_photo', $draft->manualIntake()['missing_fields']);
        Storage::disk('public')->assertExists($source->path);
    }

    private function catalog(): array
    {
        $category = Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id, 'title' => 'Sample laptop', 'model' => 'Sample 14',
            'product_type' => 'laptop', 'description' => 'Catalog description', 'is_active' => true, 'status' => 'published',
            'translations' => ['cs' => ['title' => 'Ukázkový notebook', 'description' => 'Popis']],
        ]);
        $variant = $product->variants()->create([
            'name' => 'Black', 'color' => 'Black', 'condition' => 'used', 'sku' => 'SAMPLE-14',
            'price' => '10900.00', 'currency' => 'CZK', 'quantity' => 3, 'warranty_months' => 6,
            'is_default' => true, 'is_active' => true,
        ]);
        $memory = AttributeDefinition::query()->firstOrCreate(['key' => 'memory'], ['label' => 'Memory', 'data_type' => 'text', 'is_variant' => true]);
        $cpu = AttributeDefinition::query()->firstOrCreate(['key' => 'cpu'], ['label' => 'Processor', 'data_type' => 'text']);
        $variant->attributes()->create(['attribute_definition_id' => $memory->id, 'value' => '16 GB']);
        $product->attributes()->create(['attribute_definition_id' => $cpu->id, 'value' => 'Intel Core i5']);
        $path = "products/{$product->id}/original.jpg";
        Storage::disk('public')->put($path, $this->jpeg());
        $product->media()->create([
            'product_variant_id' => $variant->id, 'type' => 'image', 'disk' => 'public', 'path' => $path,
            'width' => 1200, 'height' => 800, 'verification_status' => 'manual', 'is_primary' => true,
        ]);
        $update = TelegramUpdate::query()->create([
            'update_id' => 98001, 'chat_id' => 'chat-1', 'telegram_user_id' => 'user-1', 'status' => 'received', 'payload' => [],
        ]);

        return [$product, $variant, $update];
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1200, 800);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
