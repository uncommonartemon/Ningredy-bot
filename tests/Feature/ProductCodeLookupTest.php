<?php

namespace Tests\Feature;

use App\Models\ProductSourceAttempt;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductCodeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.icecat.username' => 'tester']);
    }

    public function test_only_barcodes_with_a_valid_check_digit_are_treated_as_gtins(): void
    {
        $this->assertSame(['0730143314930', '8806094215021'],
            ProductCodeLookup::gtins('EAN 0730143314930, order 12345678, 8806094215021, 8806094215022'));
    }

    public function test_an_icecat_datasheet_is_normalized_recorded_and_cached(): void
    {
        Http::fake(['https://live.icecat.biz/*' => Http::response(['data' => $this->datasheet()])]);
        $lookup = app(ProductCodeLookup::class);

        $first = $lookup->icecat(null, 'Lenovo', '83F500NKUS', null, 'test');
        $second = $lookup->icecat(null, 'Lenovo', '83F500NKUS', null, 'test');

        $this->assertTrue($first['found']);
        $this->assertSame('https://icecat.biz/p/lenovo/83f500nkus/legion-pro-7-145199652.html', $first['page_url']);
        $this->assertSame(['https://images.icecat.biz/img/gallery/a.jpg'], array_column($first['images'], 'url'));
        $this->assertContains('Processor · Processor model: Intel Core Ultra 9 290HX Plus', $first['specifications']);
        $this->assertSame($first['page_url'], $second['page_url']);
        Http::assertSentCount(1);
        $this->assertSame(2, ProductSourceAttempt::query()->where('action', 'lookup_icecat')->where('decision', 'found')->count());
    }

    public function test_a_datasheet_page_found_by_name_is_read_by_its_number_through_the_api(): void
    {
        // Operator, 2026-10-30: Icecat first for a name without a code. The site answers a plain
        // request with a bot check; the number in the page address is enough for the API.
        $this->assertSame('120744916', ProductCodeLookup::icecatIdFromUrl('https://icecat.biz/p/msi/stealth14a1091/stealth-14-ai-studio-a1vfg-091us-120744916.html'));
        $this->assertSame('120744916', ProductCodeLookup::icecatIdFromUrl('https://www.icecat.biz/en/p/msi/stealth-120744916.html'));
        $this->assertNull(ProductCodeLookup::icecatIdFromUrl('https://shop.example/p/msi/stealth-120744916.html'));
        $this->assertNull(ProductCodeLookup::icecatIdFromUrl('https://icecat.biz/en/search?keyword=stealth'));
        Http::fake(['https://live.icecat.biz/*' => Http::response(['data' => $this->datasheet()])]);

        $result = app(ProductCodeLookup::class)->icecatById('145199652', null, 'test');

        $this->assertTrue($result['found']);
        $this->assertSame('145199652', $result['icecat_id']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'icecat_id=145199652') && ! str_contains($request->url(), 'ProductCode'));
        $this->assertSame(1, ProductSourceAttempt::query()->where('action', 'lookup_icecat')->where('decision', 'found')->count());
    }

    public function test_a_phrase_is_not_sent_to_the_catalogue_as_a_code(): void
    {
        // Live #370: the configured model "Framework Laptop 13 DIY Edition (AMD Ryzen 7040 Series), Ryzen 7 7840U, 2.8K display"
        // went out as ProductCode and the API answered "Exceed max string length".
        Http::fake();

        app(ProductCodeLookup::class)->lookup([], 'Framework', ['Framework Laptop 13 DIY Edition (AMD Ryzen 7040 Series), Ryzen 7 7840U, 2.8K display', 'FRANDG0007R'], null, 'test');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'ProductCode=FRANDG0007R'));
    }

    public function test_the_whole_public_gallery_is_offered_however_long_it_is(): void
    {
        // 35 live searches, 2026-10-25: a cap of twelve left 49 official frames never downloaded.
        $gallery = collect(range(1, 14))->map(fn (int $no): array => ['Pic' => "https://images.icecat.biz/img/gallery/{$no}.jpg",
            'PicWidth' => '2000', 'PicHeight' => '2000', 'No' => (string) $no, 'IsPrivate' => '0'])->all();
        Http::fake(['https://live.icecat.biz/*' => Http::response(['data' => [...$this->datasheet(), 'Gallery' => $gallery]])]);

        $result = app(ProductCodeLookup::class)->icecat(null, 'Lenovo', '83F500NKUS', null, 'test');

        $this->assertCount(14, $result['images']);
        $this->assertSame('https://images.icecat.biz/img/gallery/14.jpg', $result['images'][13]['url']);
    }

    public function test_a_restricted_brand_or_a_network_failure_never_breaks_the_search(): void
    {
        Http::fake(['https://live.icecat.biz/*' => Http::sequence()
            ->push(['Code' => 403, 'Message' => 'To access Full Icecat content, an app_key is required.'], 403)
            ->push(['StatusCode' => 16, 'Code' => 400, 'Error' => 'Bad Request', 'Message' => 'The GTIN can not be found'], 400)
            ->pushFailedConnection()]);
        $lookup = app(ProductCodeLookup::class);

        $this->assertSame('restricted', $lookup->icecat(null, 'Intel', 'BX8071514900K', null, 'test')['status']);
        $this->assertSame(['Intel'], $lookup->trackRecord()['icecat_closed_brands']);
        $this->assertSame('not_found', $lookup->icecat('0197531386724', null, null, null, 'test')['status']);
        $this->assertSame('error', $lookup->icecat('0718037891408', null, null, null, 'test')['status']);
        $this->assertSame(1, $lookup->trackRecord()['icecat']['restricted_or_quota']);
        $this->assertSame(1, $lookup->trackRecord()['icecat']['not_found']);
        $this->assertSame(1, $lookup->trackRecord()['icecat']['errors']);
    }

    public function test_upcitemdb_maps_a_barcode_to_brand_and_model_and_respects_its_daily_limit(): void
    {
        config(['services.upcitemdb.daily_limit' => 1]);
        Http::fake([
            'https://live.icecat.biz/*' => Http::response(['Code' => 404, 'Message' => 'The requested product is not present in the Icecat database'], 404),
            'https://api.upcitemdb.com/*' => Http::response(['code' => 'OK', 'items' => [['ean' => '0718037891408',
                'title' => 'WD_BLACK 2TB SN850X', 'brand' => 'Western Digital', 'model' => 'WDS200T2X0E', 'images' => ['https://img.example/a.jpg']]]]),
        ]);
        $lookup = app(ProductCodeLookup::class);

        $results = $lookup->lookup(['0718037891408'], null, [], null, 'test');
        $upc = collect($results)->firstWhere('source', 'upcitemdb');
        $this->assertSame('WDS200T2X0E', $upc['part_number']);
        $this->assertSame([], $lookup->trackRecord()['icecat_closed_brands']);
        // Brand + model from the barcode are tried on Icecat too.
        $this->assertSame('Western Digital WDS200T2X0E', collect($results)->where('source', 'icecat')->last()['code']);
        $this->assertSame('quota', $lookup->upcitemdb('5032037278522', null, 'test')['status']);
    }

    public function test_a_localisation_suffix_is_dropped_when_the_full_code_is_not_a_datasheet(): void
    {
        Http::fake(function ($request) {
            return ($request['ProductCode'] ?? null) === '83F500NKUS'
                ? Http::response(['data' => $this->datasheet()])
                : Http::response(['Code' => 404, 'Message' => 'The requested product is not present in the Icecat database'], 404);
        });

        $results = app(ProductCodeLookup::class)->lookup([], 'Lenovo', ['83F500NKUS#ABA'], null, 'test');

        $this->assertSame(['Lenovo 83F500NKUS#ABA', 'Lenovo 83F500NKUS'], array_column($results, 'code'));
        $this->assertTrue($results[1]['found']);
    }

    private function datasheet(): array
    {
        return [
            'GeneralInfo' => ['Title' => 'Lenovo Legion Pro 7 16IAX10H', 'Brand' => 'Lenovo', 'BrandPartCode' => '83F500NKUS',
                'IcecatId' => '145199652', 'ProductName' => 'Legion Pro 7', 'GTIN' => ['0199275673924']],
            'Gallery' => [
                ['Pic' => 'https://images.icecat.biz/img/gallery/a.jpg', 'PicWidth' => '2000', 'PicHeight' => '2000', 'No' => '1', 'IsPrivate' => '0'],
                ['Pic' => 'https://images.icecat.biz/img/gallery/private.jpg', 'PicWidth' => '2000', 'PicHeight' => '2000', 'No' => '2', 'IsPrivate' => '1'],
            ],
            'FeaturesGroups' => [['FeatureGroup' => ['Name' => ['Value' => 'Processor']], 'Features' => [
                ['PresentationValue' => 'Intel Core Ultra 9 290HX Plus', 'Feature' => ['Name' => ['Value' => 'Processor model']]],
            ]]],
        ];
    }
}
