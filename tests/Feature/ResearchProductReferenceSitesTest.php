<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Tools\ResearchProduct;
use App\Models\AttributeDefinition;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductSourceAttempt;
use App\Models\ReferenceSite;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityKey;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Live 2026-11-02 (the colleague's #161/#163): research found an SSD's speeds on a
 * benchmark page, the SSD was already in the catalog, and the found facts were
 * dropped; the operator who had asked for exactly them was told nothing was found.
 */
class ResearchProductReferenceSitesTest extends TestCase
{
    use RefreshDatabase;

    private const string SHOP = 'https://shop.example/pm991';

    private const string BENCHMARK = 'https://www.harddrivebenchmark.net/hdd.php?hdd=SAMSUNG+PM991';

    public function test_research_hears_of_the_reference_sites_keeps_their_limits_and_returns_what_it_found_for_a_product_already_in_the_catalog(): void
    {
        $brand = Brand::query()->firstOrCreate(['slug' => 'samsung'], ['name' => 'Samsung', 'is_active' => true]);
        $existing = Product::query()->create(['category_id' => Category::query()->where('slug', 'components')->firstOrFail()->id,
            'brand_id' => $brand->id, 'canonical_key' => ProductIdentityKey::for('Samsung', 'PM991', 'Samsung PM991 512GB'),
            'product_type' => 'component', 'status' => 'published', 'title' => 'Samsung PM991 512GB', 'is_active' => true, 'published_at' => now()]);
        $storage = AttributeDefinition::query()->firstOrCreate(['key' => 'storage'], ['label' => 'Storage', 'data_type' => 'number']);
        AttributeValue::query()->create(['attribute_definition_id' => $storage->id, 'product_id' => $existing->id, 'value' => '512 GB']);
        ProductResearchAgent::fake(function (string $prompt): array {
            $this->assertStringContainsString('"site":"harddrivebenchmark.net"', $prompt);
            $this->assertStringContainsString('site:harddrivebenchmark.net PM991', $prompt);
            $this->assertStringNotContainsString('techpowerup', $prompt);

            return ['status' => 'found', 'title' => 'Samsung PM991 512GB', 'brand' => 'Samsung', 'model' => 'PM991',
                'category' => 'components', 'product_type' => 'component', 'color' => null, 'condition' => 'new',
                'description' => 'An M.2 NVMe SSD.', 'research_notes' => null, 'confidence' => 0.9,
                'specifications' => [
                    ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB', 'source_url' => self::SHOP, 'source_excerpt' => 'Samsung PM991 512GB'],
                    ['key' => 'sequential_read_speed', 'name' => 'Sequential read speed', 'value' => '2,138 MB/s',
                        'source_url' => self::BENCHMARK, 'source_excerpt' => 'Sequential Read 2,138 MBytes/Sec'],
                ],
                'sources' => [
                    ['title' => 'PM991 at a shop', 'url' => self::SHOP, 'type' => 'retailer', 'roles' => ['facts', 'exact_photo'],
                        'applicability_reason' => 'Sells this drive.', 'image_urls' => []],
                    ['title' => 'PassMark PM991', 'url' => self::BENCHMARK, 'type' => 'review', 'roles' => ['facts', 'exact_photo'],
                        'applicability_reason' => 'Benchmarks of this drive.', 'image_urls' => ['https://www.harddrivebenchmark.net/pm991.png']],
                ],
                'primary_source_url' => self::SHOP, 'official_source_url' => null, 'image_urls' => ['https://www.harddrivebenchmark.net/pm991.png'],
                'reference_site_results' => [['site' => 'harddrivebenchmark.net', 'result' => 'found'], ['site' => 'intel.com', 'result' => 'not_applicable']],
                'selected_identity' => $this->observedIdentity('PM991', self::SHOP)];
        });
        $update = TelegramUpdate::query()->create(['update_id' => random_int(2000, 9000), 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => 55, 'text' => 'Samsung PM991 512GB добавь скорость', 'payload' => [], 'status' => 'received']);

        $result = json_decode((new ResearchProduct($update, $this->mock(ProductImageResolver::class)))
            ->handle(new Request(['query' => 'Samsung PM991 512GB SSD'])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('already_in_catalog', $result['status']);
        $this->assertSame($existing->id, $result['product_id']);
        $this->assertSame([['key' => 'sequential_read_speed', 'name' => 'Sequential read speed', 'value' => '2,138 MB/s',
            'source_url' => self::BENCHMARK, 'source_excerpt' => 'Sequential Read 2,138 MBytes/Sec']], $result['found_specifications']);
        $this->assertStringContainsString('AddProductSpecifications', $result['message']);
        // The benchmark site is facts-only: its page stays a source of facts and gives no photo.
        $sources = collect(ProductSourceAttempt::query()->where('action', 'research_summary')->sole()->output['sources']);
        $this->assertSame(['facts'], $sources->firstWhere('url', self::BENCHMARK)['roles']);
        $this->assertSame([], $sources->firstWhere('url', self::BENCHMARK)['image_urls']);
        $site = ReferenceSite::query()->where('domain', 'harddrivebenchmark.net')->firstOrFail();
        $this->assertSame([1, 0], [$site->hits, $site->misses]);
        $this->assertSame(0, ReferenceSite::query()->where('domain', 'intel.com')->firstOrFail()->misses);
    }
}
