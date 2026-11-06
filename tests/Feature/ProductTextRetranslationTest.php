<?php

namespace Tests\Feature;

use App\Ai\Tools\UpdateProduct;
use App\Ai\Tools\UpdateVariant;
use App\Jobs\TranslateProductText;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Module 72: the storefront reads the translated text. A title changed from the chat
 * used to stay old on the Czech and Ukrainian pages: the product dropped its stale
 * translation, the variant kept its copy, and nothing made a new one.
 */
class ProductTextRetranslationTest extends TestCase
{
    use RefreshDatabase;

    private int $updates = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_changed_title_drops_the_stale_copies_and_the_chat_tool_orders_new_translations(): void
    {
        [$product, $variant] = $this->product();

        $result = json_decode((new UpdateProduct($this->ask('Измени название')))->handle(new Request(['product_id' => $product->id, 'title' => 'New title'])), true);

        $this->assertTrue($result['ok']);
        $this->assertSame('New title', $product->fresh()->title);
        $this->assertArrayNotHasKey('title', $product->fresh()->translations['uk']);
        $this->assertSame('Старий опис', $product->fresh()->translations['uk']['description']);
        $this->assertArrayNotHasKey('title', $variant->fresh()->translations['uk']);
        $this->assertSame('Старий опис', $variant->fresh()->translations['uk']['description']);
        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);
    }

    public function test_the_same_title_orders_nothing_and_a_variants_own_text_stays(): void
    {
        [$product, $variant] = $this->product();
        $variant->update(['translations' => ['uk' => ['title' => 'Своя назва', 'description' => 'Старий опис']]]);

        (new UpdateProduct($this->ask('То же название')))->handle(new Request(['product_id' => $product->id, 'title' => $product->title]));
        Queue::assertNotPushed(TranslateProductText::class);

        (new UpdateProduct($this->ask('Новое название')))->handle(new Request(['product_id' => $product->id, 'title' => 'New title']));
        $this->assertSame('Своя назва', $variant->fresh()->translations['uk']['title']);
    }

    public function test_the_variant_tool_changes_codes_condition_and_warranty_and_refuses_a_bad_condition(): void
    {
        [, $variant] = $this->product();
        $tool = new UpdateVariant($this->ask('Поправь коды'));

        $this->assertTrue(json_decode($tool->handle(new Request(['variant_id' => $variant->id, 'mpn' => 'MZ-1', 'gtin' => '8806094215038', 'sku' => 'S-1', 'condition' => 'used', 'warranty_months' => 12])), true)['ok']);
        $fresh = $variant->fresh();
        $this->assertSame(['MZ-1', '8806094215038', 'S-1', 'used', 12], [$fresh->mpn, $fresh->gtin, $fresh->sku, $fresh->condition, $fresh->warranty_months]);

        $this->assertFalse(json_decode($tool->handle(new Request(['variant_id' => $variant->id, 'condition' => 'broken'])), true)['ok']);
    }

    public function test_a_batch_that_changes_shown_text_orders_translations_once_per_product(): void
    {
        [$product, $variant] = $this->product();
        $definition = AttributeDefinition::query()->firstOrCreate(['key' => 'tdp'], ['label' => 'TDP', 'data_type' => 'text']);
        $value = AttributeValue::query()->create(['attribute_definition_id' => $definition->id, 'product_id' => $product->id, 'value' => '100 W']);
        $service = app(DatabaseOperatorService::class);
        $context = $this->ask('Поправь товар');

        $plan = $service->prepare([
            ['action' => 'update', 'table' => 'products', 'selection' => ['ids' => [$product->id]], 'values' => ['description' => 'New description']],
            ['action' => 'update', 'table' => 'attribute_values', 'selection' => ['ids' => [$value->id]], 'values' => ['value' => '115 W']],
        ], $context);
        $service->execute($plan, $context);

        $this->assertSame('115 W', $value->fresh()->value);
        Queue::assertPushed(TranslateProductText::class, 1);
        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);
    }

    public function test_a_published_card_whose_edit_dropped_a_translation_gets_it_back(): void
    {
        Storage::fake('public');
        $category = Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true, 'product_type_affinity' => 'laptop']);
        $update = $this->ask('Find fixture laptop');
        $run = $update->aiRuns()->create(['provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'title' => 'Fixture laptop', 'brand' => 'Dell', 'model' => 'Latitude 5450', 'description' => 'A fixture.', 'status' => 'pending_review',
            'category' => $category->slug, 'product_type' => 'laptop', 'condition' => 'new',
            'specifications' => [['key' => 'tdp', 'name' => 'TDP', 'value' => 'Up to 115 W', 'operator_stated' => true]],
            'translations' => ['uk' => ['title' => 'Ноутбук', 'description' => 'Опис'], 'cs' => ['title' => 'Notebook', 'description' => 'Popis']],
            'sources' => [], 'image_urls' => [], 'primary_source_url' => 'https://shop.example/exact', 'gallery_status' => 'complete', 'images_staged_at' => now(),
            'specifications_reconciled_source_url' => 'https://shop.example/exact']);
        Storage::disk('public')->put("drafts/{$draft->id}/1.jpg", 'fake');
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/1.jpg", 'checksum' => hash('sha256', 'fake'),
            'width' => 1000, 'height' => 800, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);

        $product = app(ProductDraftWorkflow::class)->approve($draft, telegramReviewerId: '12345');

        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);
    }

    /** @return array{Product, ProductVariant} */
    private function product(): array
    {
        $category = Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true, 'product_type_affinity' => 'laptop']);
        $product = Product::query()->create(['category_id' => $category->id, 'title' => 'Old title', 'description' => 'Old description', 'product_type' => 'laptop', 'status' => 'published', 'is_active' => true,
            'translations' => ['uk' => ['title' => 'Стара назва', 'description' => 'Старий опис']]]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'fixture-'.$product->id, 'name' => 'Base', 'is_default' => true, 'is_active' => true,
            'translations' => ['uk' => ['title' => 'Стара назва', 'description' => 'Старий опис']]]);

        return [$product, $variant];
    }

    private function ask(string $text): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => 930000 + (++$this->updates), 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => $text, 'payload' => [], 'status' => 'processing']);
    }
}
