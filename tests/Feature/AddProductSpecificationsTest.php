<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Ai\Tools\AddProductSpecifications;
use App\Ai\Tools\ManageSearchSettings;
use App\Jobs\TranslateProductText;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReferenceSite;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

/**
 * Live 2026-11-02: a colleague asked for an SSD's speeds; research found them on a
 * benchmark page, the product was already in the catalog, nothing could write them
 * in, and the bot answered that the page had no numbers.
 */
class AddProductSpecificationsTest extends TestCase
{
    use RefreshDatabase;

    private const string BENCHMARK = 'https://www.harddrivebenchmark.net/hdd.php?hdd=SAMSUNG+MZALQ512HALU-000L1';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_values_the_operator_wrote_are_added_and_a_number_they_did_not_write_is_not(): void
    {
        $product = $this->ssd();
        $tool = new AddProductSpecifications($this->update('Добавь в #'.$product->id.': Sequential read 2,200 MB/s, write 1200 MB/s'));

        $result = json_decode($tool->handle(new ToolRequest(['product_id' => $product->id, 'wanted' => 'скорость',
            'facts_json' => json_encode([
                ['name' => 'Sequential read speed', 'value' => '2200 MB/s', 'key' => 'sequential_read_speed', 'from_operator' => true],
                ['name' => 'Sequential write speed', 'value' => '1350 MB/s', 'key' => 'sequential_write_speed', 'from_operator' => true],
            ])])), true);

        $this->assertTrue($result['ok']);
        $this->assertSame([['name' => 'Sequential read speed', 'value' => '2200 MB/s', 'source' => 'operator']], $result['added']);
        $this->assertSame([['name' => 'Sequential write speed', 'value' => '1350 MB/s', 'reason' => 'этого значения нет в сообщении оператора']], $result['rejected']);
        $this->assertSame('2200 MB/s', $this->value($product, 'sequential_read_speed'));
        $this->assertNull($this->value($product, 'sequential_write_speed'));
        Queue::assertPushed(TranslateProductText::class, fn (TranslateProductText $job): bool => $job->productId === $product->id);
    }

    public function test_a_named_configuration_takes_the_values_as_its_own_and_another_configurations_value_never_blocks_it(): void
    {
        // Audit 2026-11-04, C5: every configuration's values counted as «already in the product».
        $product = $this->ssd();
        $small = $product->variants()->sole();
        $large = ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'pm991-1tb', 'name' => '1 TB',
            'sku' => 'MZALQ1T0HBLB-000L1', 'is_default' => false, 'is_active' => true]);
        $read = AttributeDefinition::query()->firstOrCreate(['key' => 'sequential_read_speed'], ['label' => 'Sequential read speed', 'data_type' => 'text']);
        AttributeValue::query()->create(['attribute_definition_id' => $read->id, 'product_variant_id' => $small->id, 'value' => '2200 MB/s']);
        $tool = new AddProductSpecifications($this->update('Для версии 1 ТБ товара #'.$product->id.': Sequential read 3100 MB/s'));

        $result = json_decode($tool->handle(new ToolRequest(['product_id' => $product->id, 'product_variant_id' => $large->id, 'wanted' => 'скорость',
            'facts_json' => json_encode([['name' => 'Sequential read speed', 'value' => '3100 MB/s', 'key' => 'sequential_read_speed', 'from_operator' => true]])])), true);

        $this->assertTrue($result['ok']);
        $this->assertSame(['id' => $large->id, 'name' => '1 TB', 'sku' => 'MZALQ1T0HBLB-000L1'], $result['configuration']);
        $this->assertSame([], $result['already_in_product']);
        $this->assertSame('3100 MB/s', AttributeValue::query()->where('attribute_definition_id', $read->id)->where('product_variant_id', $large->id)->value('value'));
        $this->assertSame('2200 MB/s', AttributeValue::query()->where('attribute_definition_id', $read->id)->where('product_variant_id', $small->id)->value('value'));

        $other = json_decode($tool->handle(new ToolRequest(['product_id' => $product->id, 'product_variant_id' => 999999, 'wanted' => 'скорость'])), true);
        $this->assertFalse($other['ok']);
        $this->assertSame([$small->id, $large->id], array_column($other['configurations'], 'id'));
    }

    public function test_a_value_the_product_already_has_is_kept_unless_the_operator_asked_to_correct_it(): void
    {
        $product = $this->ssd();
        $facts = json_encode([['name' => 'Storage', 'value' => '1 TB', 'key' => 'storage', 'from_operator' => true]]);
        $update = $this->update('Исправь объём в #'.$product->id.' на 1 TB');

        $kept = json_decode((new AddProductSpecifications($update))->handle(new ToolRequest(['product_id' => $product->id,
            'wanted' => 'объём', 'facts_json' => $facts])), true);
        $this->assertSame([['current' => '512 GB', 'found' => '1 TB']], array_map(fn (array $item): array => array_diff_key($item, ['name' => true]), $kept['already_in_product']));
        $this->assertSame('512 GB', $this->value($product, 'storage'));

        (new AddProductSpecifications($update))->handle(new ToolRequest(['product_id' => $product->id,
            'wanted' => 'объём', 'facts_json' => $facts, 'replace_existing' => true]));
        $this->assertSame('1 TB', $this->value($product, 'storage'));
    }

    public function test_the_web_search_writes_only_quoted_facts_about_this_product_and_offers_the_sites_a_person_opens(): void
    {
        $product = $this->ssd();
        ProductTextEvidenceAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertContains('MZALQ512HALU-000L1', $payload['selected_product']['codes']);
            $this->assertSame('скорость чтения и записи', $payload['missing_or_conflicting_facts']);
            $this->assertContains('harddrivebenchmark.net', array_column($payload['reference_sites'], 'site'));
            $this->assertNotContains('techpowerup.com', array_column($payload['reference_sites'], 'site'));

            return ['summary' => 'Read and write speeds measured by PassMark users.', 'facts' => [
                $this->fact('sequential_read_speed', 'Sequential read speed', '2,138 MB/s', 'Sequential Read 2,138 MBytes/Sec', 'exact_configuration'),
                $this->fact('sequential_write_speed', 'Sequential write speed', '1,355 MB/s', 'Sequential Write 1,200 MBytes/Sec', 'exact_configuration'),
                $this->fact('random_read_iops', 'Random read IOPS', '350000', 'PM991 family: up to 350K IOPS', 'product_family'),
            ]];
        })->preventStrayPrompts();

        $result = json_decode((new AddProductSpecifications($this->update('Добавь скорость чтения и записи в #'.$product->id)))
            ->handle(new ToolRequest(['product_id' => $product->id, 'wanted' => 'скорость чтения и записи'])), true);

        $this->assertSame([['name' => 'Sequential read speed', 'value' => '2,138 MB/s', 'source' => self::BENCHMARK]], $result['added']);
        $this->assertSame(['значения нет в цитате со страницы', 'страница говорит о другой конфигурации или о всём семействе'],
            array_column($result['rejected'], 'reason'));
        $this->assertSame('2,138 MB/s', $this->value($product, 'sequential_read_speed'));
        $this->assertTrue($product->sources()->where('url', self::BENCHMARK)->exists());
        $this->assertSame(1, ReferenceSite::query()->where('domain', 'harddrivebenchmark.net')->firstOrFail()->hits);
        $this->assertContains('https://www.techpowerup.com/ssd-specs/?q=PM991%20MZALQ512HALU-000L1', array_column($result['person_links'], 'link'));
    }

    public function test_a_banned_page_and_a_page_without_quotation_are_never_written(): void
    {
        $product = $this->ssd();
        app(ProductGalleryRecipeRouter::class)->blockDomain('bad-specs.example', 'test');
        app()->forgetInstance(ProductSourcePriority::class);
        $facts = json_encode([
            ['name' => 'Interface', 'value' => 'PCIe 3.0 x4', 'key' => 'interface', 'source_url' => 'https://bad-specs.example/pm991', 'source_excerpt' => 'Interface PCIe 3.0 x4'],
            ['name' => 'Controller', 'value' => 'Samsung Phoenix', 'key' => 'controller', 'source_url' => 'https://good.example/pm991'],
        ]);

        $result = json_decode((new AddProductSpecifications($this->update('допиши интерфейс')))->handle(new ToolRequest([
            'product_id' => $product->id, 'wanted' => 'интерфейс', 'facts_json' => $facts])), true);

        $this->assertSame([], $result['added']);
        $this->assertSame(['сайт заблокирован', 'значения нет в цитате со страницы'], array_column($result['rejected'], 'reason'));
    }

    public function test_the_chat_bans_a_site_and_sets_the_search_budget(): void
    {
        $tool = new ManageSearchSettings($this->update('забань bad-shop.example и поставь бюджет 0,5 доллара'));

        $banned = json_decode($tool->handle(new ToolRequest(['action' => 'block_site', 'site' => 'https://www.bad-shop.example/p/1', 'reason' => 'чужие фото'])), true);
        $this->assertTrue($banned['ok']);
        app()->forgetInstance(ProductSourcePriority::class);
        $this->assertTrue(app(ProductSourcePriority::class)->isBlockedUrl('https://bad-shop.example/other'));

        $budget = json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'max_search_cost_usd', 'value' => '0,5'])), true);
        $this->assertTrue($budget['ok']);
        $this->assertSame(0.5, app(AiSettings::class)->maxSearchCostUsd());

        $switch = json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'fallback_sources_enabled', 'value' => 'false'])), true);
        $this->assertTrue($switch['ok']);
        $this->assertFalse(app(AiSettings::class)->fallbackSourcesEnabled());

        $refused = json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'openai_api_key', 'value' => 'sk-x'])), true);
        $this->assertFalse($refused['ok']);
        // A switch without a value is not switched off (audit 2026-11-04, C8).
        $noValue = json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'fallback_sources_enabled'])), true);
        $this->assertFalse($noValue['ok']);
        $this->assertStringContainsString('Не указано значение', $noValue['error']);
        $this->assertFalse(app(AiSettings::class)->fallbackSourcesEnabled());

        json_decode($tool->handle(new ToolRequest(['action' => 'unblock_site', 'site' => 'bad-shop.example'])), true);
        app()->forgetInstance(ProductSourcePriority::class);
        $this->assertFalse(app(ProductSourcePriority::class)->isBlockedUrl('https://bad-shop.example/other'));
    }

    /** The quotation check reads numbers with their units, not bare digits (audit 2026-11-04, C1). */
    public function test_a_value_is_quoted_only_as_a_whole_number_with_its_unit_or_as_whole_words(): void
    {
        $quotes = fn (string $value, string $text): bool => AddProductSpecifications::quotes($value, $text);
        $this->assertTrue($quotes('2,138 MB/s', 'Sequential Read 2,138 MBytes/Sec'));
        $this->assertTrue($quotes('2200 MB/s', 'up to 2 200 MB/s read'));
        $this->assertTrue($quotes('DDR4-3200', 'память DDR4 3200 MHz SO-DIMM'));
        $this->assertTrue($quotes('Samsung Phoenix', 'Controller: Samsung Phoenix'));
        $this->assertFalse($quotes('16 GB', 'Windows 11 Pro, 16 cores, 8 GB DDR4'));
        $this->assertFalse($quotes('PCIe 4.0 x4', 'Interface: PCIe 3.0 x4, length 40 mm'));
        $this->assertFalse($quotes('No', 'Nominal capacity 512 GB'));
        $this->assertFalse($quotes('15 W', 'Добавь в #15 TDP'));
        $this->assertFalse($quotes('16', 'part 160 of 1600'));
    }

    private function ssd(): Product
    {
        $product = Product::query()->create(['title' => 'Samsung PM991 512GB NVMe SSD', 'model' => 'PM991 MZALQ512HALU-000L1',
            'category_id' => Category::query()->where('slug', 'components')->firstOrFail()->id, 'product_type' => 'component',
            'status' => 'published', 'is_active' => true]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'fingerprint' => 'pm991', 'name' => 'Default',
            'sku' => 'MZALQ512HALU-000L1', 'is_default' => true, 'is_active' => true]);
        // Storage is a configuration's own characteristic, kept on the variant as an approved card keeps it.
        $storage = AttributeDefinition::query()->firstOrCreate(['key' => 'storage'], ['label' => 'Накопитель', 'data_type' => 'number']);
        AttributeValue::query()->create(['attribute_definition_id' => $storage->id, 'product_variant_id' => $variant->id, 'value' => '512 GB']);

        return $product;
    }

    private function update(string $text): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => random_int(1000, 900000), 'telegram_user_id' => '111', 'chat_id' => '222',
            'message_id' => 1, 'text' => $text, 'payload' => [], 'status' => 'processing']);
    }

    private function value(Product $product, string $key): ?string
    {
        return AttributeValue::query()->whereHas('definition', fn ($query) => $query->where('key', $key))
            ->where(fn ($query) => $query->where('product_id', $product->id)->orWhereIn('product_variant_id', $product->variants()->pluck('id')))
            ->value('value');
    }

    private function fact(string $key, string $name, string $value, string $excerpt, string $scope): array
    {
        return ['key' => $key, 'name' => $name, 'value' => $value, 'group' => null, 'source_url' => self::BENCHMARK,
            'source_excerpt' => $excerpt, 'source_type' => 'review', 'scope' => $scope, 'applicability' => 'The page is this exact drive.'];
    }
}
