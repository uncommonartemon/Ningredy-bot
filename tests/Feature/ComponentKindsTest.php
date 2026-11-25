<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\CatalogPlacement;
use App\Services\Catalog\CategoryStandard;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\RequiredSpecifications;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Database\Seeders\StarterCatalogSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Module 69: the kinds of components under «Комплектующие» (operator's decision, 2026-11-04), each naming the characteristics its card must carry;
 * what research did not find, the card says instead of staying silent.
 */
class ComponentKindsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_kinds_are_seeded_under_components_with_their_required_characteristics(): void
    {
        $components = Category::query()->where('slug', 'components')->sole();
        $ssd = Category::query()->where('slug', 'ssd')->sole();

        $this->assertSame((int) $components->id, (int) $ssd->parent_id);
        $this->assertSame('component', $ssd->product_type_affinity);
        $this->assertSame('label', $ssd->photo_identity);
        $this->assertSame('playwright_first', $ssd->gallery_search_strategy);
        $this->assertSame(['mpn', 'storage', 'form_factor', 'interface'], $ssd->requiredAttributeDefinitions()->pluck('key')->all());
        $this->assertSame(['cs' => 'SSD disky', 'en' => 'SSD drives', 'uk' => 'SSD-накопичувачі'],
            $ssd->translations()->orderBy('locale')->pluck('name', 'locale')->all());
        $rpm = AttributeDefinition::query()->where('key', 'rpm')->sole();
        $this->assertSame(['number', 'rpm', false], [$rpm->data_type, $rpm->default_unit, $rpm->is_variant]);
        // A used part's wear belongs to the item sold, not to every item of the model.
        $this->assertTrue(AttributeDefinition::query()->where('key', 'health_percent')->sole()->is_variant);

        // Seeding again adds nothing, and a kind the operator tuned keeps its rows.
        $ssd->update(['product_search_hint' => 'моя подсказка']);
        DB::table('attribute_definition_category')->where('category_id', $ssd->id)
            ->where('attribute_definition_id', AttributeDefinition::query()->where('key', 'form_factor')->value('id'))->delete();
        $counts = fn (): array => [Category::query()->count(), AttributeDefinition::query()->count(), DB::table('attribute_definition_category')->count()];
        $before = $counts();

        app(StarterCatalogSeeder::class)->run();

        $this->assertSame($before, $counts());
        $this->assertSame('моя подсказка', $ssd->fresh()->product_search_hint);
        $this->assertSame(['mpn', 'storage', 'interface'], $ssd->requiredAttributeDefinitions()->pluck('key')->all());
    }

    public function test_the_placement_keeps_the_kind_research_chose_and_puts_the_rest_in_the_parent_saying_the_kind_is_open(): void
    {
        $placement = app(CatalogPlacement::class);

        $this->assertSame(['ssd', false], [$placement->place('component', 'ssd')->slug(), $placement->place('component', 'ssd')->awaitsKind()]);
        // Before: several categories accepted «component», so a draft without a kind had no category at all.
        // Then a resolver put it in the parent silently; now the placement says the kind is still open.
        $this->assertSame(['components', true], [$placement->place('component', null)->slug(), $placement->place('component', null)->awaitsKind()]);
        $this->assertSame('components', $placement->place('component', 'laptops')->slug());
        $this->assertSame('laptops', $placement->place('laptop', 'ssd')->slug());
    }

    public function test_research_reads_each_kind_under_its_parent_and_the_form_keys_by_slug(): void
    {
        $instructions = (string) (new ProductResearchAgent)->instructions();

        // The map shows a kind indented under its parent; the type is written once, on the parent.
        $this->assertStringContainsString("- components: Components [component]\n  - ssd: SSD-накопители / SSD drives\n", $instructions);
        $this->assertStringContainsString("\n  - ram: Оперативная память / Memory modules\n", $instructions);
        $this->assertStringContainsString('form: mpn (Manufacturer part number; required), ram (Memory; required), memory_type (Memory type; required), data_rate (Data rate; required), module_type (Module type; required)', $instructions);
        preg_match('/^- laptops: (?:search hint|form)[^\n]*/mu', $instructions, $laptops);
        $this->assertStringNotContainsString('required', $laptops[0] ?? '');
        $this->assertStringContainsString('a field no page shows is left out, never guessed', $instructions);
    }

    public function test_the_card_names_the_required_characteristics_research_did_not_find(): void
    {
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));
        $draft = $this->draft('ssd', [
            ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'HFM512GDJTNG-8310A'],
            ['key' => 'storage', 'name' => 'Storage capacity', 'value' => '512 GB'],
        ]);

        $this->assertSame(['form_factor', 'interface'], array_column(RequiredSpecifications::forDraft($draft), 'key'));
        $this->assertStringStartsWith('❓ Не найдено: ', (string) RequiredSpecifications::cardLine($draft));
        // The card shows the category's form report (decision of 2026-11-13), naming the same missing fields.
        $line = (string) app(CategoryStandard::class)->reportLine($draft);
        $this->assertStringContainsString('❓ Не найдено: ', $line);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '98765', $draft->fresh());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], $line));

        $this->assertNull(RequiredSpecifications::cardLine($this->draft('ssd', [
            ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'HFM512GDJTNG-8310A'],
            ['key' => 'storage', 'name' => 'Storage capacity', 'value' => '512 GB'],
            ['key' => 'form_factor', 'name' => 'Form factor', 'value' => 'M.2 2280'],
            ['key' => 'interface', 'name' => 'Interface', 'value' => 'PCIe 3.0 x4 NVMe'],
        ])));
        $this->assertNull(RequiredSpecifications::cardLine($this->draft('laptops', [
            ['key' => 'cpu', 'name' => 'Processor', 'value' => 'Intel Core i5-1335U'],
        ], 'laptop')));
    }

    public function test_approval_stores_a_value_by_its_definition_type_and_scope(): void
    {
        Storage::fake('public');
        Queue::fake([StoreProductImages::class]);
        $draft = $this->draft('hdd', [
            ['key' => 'mpn', 'name' => 'Manufacturer part number', 'value' => 'ST1000LM035-1RK172'],
            ['key' => 'storage', 'name' => 'Capacity', 'value' => '1 TB'],
            ['key' => 'rpm', 'name' => 'Spindle speed', 'value' => '5400 rpm'],
            ['key' => 'interface', 'name' => 'Interface', 'value' => 'SATA 6 Gb/s'],
            ['key' => 'power_on_hours', 'name' => 'Power-on hours', 'value' => '12345 h'],
        ], withMedia: true);

        $product = app(ProductDraftWorkflow::class)->approve($draft->fresh());

        $this->assertSame('hdd', $product->category->slug);
        $byKey = fn (string $key): callable => fn (Builder $query) => $query->where('key', $key);
        $rpm = $product->attributes()->whereHas('definition', $byKey('rpm'))->sole();
        $this->assertSame([5400.0, 'rpm'], [(float) $rpm->numeric_value, $rpm->unit]);
        $interface = $product->attributes()->whereHas('definition', $byKey('interface'))->sole();
        $this->assertSame(['SATA 6 Gb/s', null], [$interface->value, $interface->numeric_value]);
        $variant = $product->defaultVariant()->sole();
        $hours = $variant->attributes()->whereHas('definition', $byKey('power_on_hours'))->sole();
        $this->assertSame([12345.0, 'h'], [(float) $hours->numeric_value, $hours->unit]);
        $storage = $variant->attributes()->whereHas('definition', $byKey('storage'))->sole();
        $this->assertSame([1024.0, 'GB'], [(float) $storage->numeric_value, $storage->unit]);
    }

    public function test_the_storefront_lists_a_kind_under_its_parent_category(): void
    {
        $brand = Brand::query()->create(['name' => 'SK hynix', 'slug' => 'sk-hynix', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => Category::query()->where('slug', 'ssd')->value('id'), 'brand_id' => $brand->id,
            'product_type' => 'component', 'status' => 'published', 'is_active' => true,
            'title' => 'SK hynix BC501 512GB', 'slug' => 'sk-hynix-bc501-512gb', 'canonical_key' => 'sk-hynix-bc501-512gb',
        ]);
        $product->variants()->create(['sku' => 'BC501-512', 'price' => 900, 'is_default' => true, 'is_active' => true, 'stock_status' => 'in_stock']);

        $page = $this->get('/catalog?category=components')->assertOk()->viewData('page')['props'];

        $this->assertSame(['SK hynix BC501 512GB'], array_column($page['products']['data'], 'title'));
        $categories = collect($page['facets']['categories'])->keyBy('value');
        $this->assertSame([1, 1, 'components', null],
            [$categories['components']['count'], $categories['ssd']['count'], $categories['ssd']['parent'], $categories['components']['parent']]);
        $this->assertSame([], $this->get('/catalog?category=laptops')->assertOk()->viewData('page')['props']['products']['data']);
    }

    /** @param  list<array{key: string, name: string, value: string}>  $specifications */
    private function draft(string $category, array $specifications, string $productType = 'component', bool $withMedia = false): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => random_int(2000, 900000), 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => 'SK hynix BC501', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'SK hynix BC501 512GB', 'brand' => 'SK hynix', 'model' => 'HFM512GDJTNG-8310A',
            'description' => 'A 512 GB NVMe drive.', 'status' => 'pending_review', 'category' => $category, 'product_type' => $productType,
            'condition' => 'new', 'specifications' => $specifications, 'sources' => [['url' => 'https://shop.example/bc501', 'title' => 'BC501']],
            'image_urls' => [], 'primary_source_url' => 'https://shop.example/bc501',
            'specifications_reconciled_source_url' => 'https://shop.example/bc501', 'gallery_status' => 'complete', 'images_staged_at' => now()]);
        if ($withMedia) {
            $image = imagecreatetruecolor(800, 800);
            ob_start();
            imagewebp($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $path = 'drafts/'.$draft->id.'/test.webp';
            Storage::disk('public')->put($path, $bytes);
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => 'https://shop.example/test.webp', 'role' => 'primary',
                'mime_type' => 'image/webp', 'width' => 800, 'height' => 800, 'file_size' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
                'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);
        }

        return $draft;
    }
}
