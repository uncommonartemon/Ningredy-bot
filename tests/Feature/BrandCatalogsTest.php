<?php

namespace Tests\Feature;

use App\Models\BrandCatalog;
use App\Models\ProductSourceAttempt;
use App\Services\ProductSearch\Catalogs\BrandCatalogs;
use Database\Seeders\BrandCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The operator's table of brand catalogs (2026-10-27): "brand -> its catalog
 * API, theirs or ours". One generic reader; a new brand is a new row.
 */
class BrandCatalogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_or_its_alias_named_in_a_request_finds_its_catalog_and_codes_fill_the_search(): void
    {
        BrandCatalog::query()->create(['brand' => 'Western Digital', 'aliases' => ['WD', 'WD_BLACK'],
            'catalog_url' => 'https://www.westerndigital.com', 'search_hint' => 'site:westerndigital.com {code}', 'enabled' => true]);
        BrandCatalog::query()->create(['brand' => 'Lenovo', 'search_hint' => 'site:psref.lenovo.com {code}', 'enabled' => false]);
        $catalogs = app(BrandCatalogs::class);

        $found = $catalogs->forText('WD Black SN850X 2TB WDS200T2X0E');
        $hints = $catalogs->hints($found, BrandCatalogs::codesIn('WD Black SN850X 2TB WDS200T2X0E'));

        $this->assertSame(['Western Digital'], $found->pluck('brand')->all());
        $this->assertSame(['site:westerndigital.com SN850X', 'site:westerndigital.com WDS200T2X0E'], $hints[0]['search']);
        // A word inside another word is not the brand; a disabled catalog is not offered.
        $this->assertCount(0, $catalogs->forText('Swd thing'));
        $this->assertCount(0, $catalogs->forText('Lenovo Legion 5 15IRX9'));
        // Without a code the agent gets the search to fill itself.
        $this->assertSame(['site:westerndigital.com {code}'], $catalogs->hints($found, [])[0]['search']);
    }

    public function test_a_page_template_adds_the_brands_page_for_the_selected_code(): void
    {
        BrandCatalog::query()->create(['brand' => 'Acme', 'lookup_template' => 'https://acme.example/p/{code}', 'enabled' => true]);

        $sources = app(BrandCatalogs::class)->sourcesFor('acme', ['AB-12 C', 'X1'], 'Model 9', null);

        $this->assertSame(['https://acme.example/p/AB-12%20C', 'https://acme.example/p/X1'], array_column(array_column($sources, 'source'), 'url'));
        $this->assertTrue($sources[0]['source']['_brand_catalog']);
        $this->assertSame('manufacturer', $sources[0]['source']['type']);
    }

    public function test_a_public_api_gives_photos_and_facts_by_its_field_map_and_counts_hits_and_misses(): void
    {
        $catalog = BrandCatalog::query()->create(['brand' => 'Acme', 'api_template' => 'https://api.acme.example/products?sku={code}',
            'response_map' => ['images' => 'data.images[*].url', 'title' => 'data.name', 'text' => 'data.specs[*]'], 'enabled' => true]);
        Http::fake([
            'https://api.acme.example/products?sku=GOOD1' => Http::response(['data' => ['name' => 'Acme Book 14',
                'images' => [['url' => 'https://cdn.acme.example/1.jpg'], ['url' => 'https://cdn.acme.example/2.jpg'], ['url' => 'not-a-url']],
                'specs' => ['RAM: 16 GB', 'SSD: 1 TB']]]),
            'https://api.acme.example/products?sku=NONE' => Http::response(['data' => null]),
        ]);
        $catalogs = app(BrandCatalogs::class);

        $found = $catalogs->sourcesFor('Acme', ['GOOD1'], null, null);
        $missed = $catalogs->sourcesFor('Acme', ['NONE'], null, null);

        $this->assertSame(['https://cdn.acme.example/1.jpg', 'https://cdn.acme.example/2.jpg'], $found[0]['source']['image_urls']);
        $this->assertSame('Acme Book 14', $found[0]['source']['title']);
        $this->assertSame("RAM: 16 GB\nSSD: 1 TB", $found[0]['specification_text']);
        $this->assertSame([], $missed);
        $catalog->refresh();
        $this->assertSame([1, 1], [$catalog->hits, $catalog->misses]);
        $this->assertSame(2, ProductSourceAttempt::query()->where('action', 'read_brand_catalog_api')->count());
    }

    public function test_a_catalog_that_never_answers_switches_itself_off_with_a_note(): void
    {
        $catalog = BrandCatalog::query()->create(['brand' => 'Acme', 'enabled' => true, 'misses' => 14]);

        app(BrandCatalogs::class)->note($catalog, false, 'API: ничего по коду');

        $this->assertFalse($catalog->refresh()->enabled);
        $this->assertStringStartsWith('Отключён автоматически: 15 промахов', $catalog->last_result);
    }

    public function test_dot_paths_read_lists_and_the_seed_fills_once_without_overwriting(): void
    {
        $this->assertSame(['a', 'b'], BrandCatalogs::read(['x' => [['u' => 'a'], ['u' => 'b']]], 'x[*].u'));
        $this->assertSame([], BrandCatalogs::read(['x' => 1], ''));

        $this->seed(BrandCatalogSeeder::class);
        BrandCatalog::query()->where('brand', 'Lenovo')->update(['instructions' => 'operator text']);
        $this->seed(BrandCatalogSeeder::class);

        $this->assertSame('operator text', BrandCatalog::query()->where('brand', 'Lenovo')->value('instructions'));
        $this->assertSame(1, BrandCatalog::query()->where('brand', 'Lenovo')->count());
    }
}
