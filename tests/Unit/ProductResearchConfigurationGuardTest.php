<?php

namespace Tests\Unit;

use App\Services\Products\ProductResearchConfigurationGuard;
use Tests\TestCase;

class ProductResearchConfigurationGuardTest extends TestCase
{
    public function test_it_rejects_a_family_with_alternative_hardware(): void
    {
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => 'Titan 18 HX AI A2XW',
            'primary_source_url' => 'https://manufacturer.example/titan',
            'sources' => [[
                'url' => 'https://manufacturer.example/titan',
                'type' => 'manufacturer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => 'A2XW family; variants include A2XWJG and A2XWIG'],
                ['key' => 'gpu', 'value' => 'RTX 5090 or RTX 5080, varies by SKU'],
            ],
        ]);

        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->contains(fn (string $issue): bool => str_starts_with($issue, 'gpu ')));
    }

    public function test_it_does_not_reject_ordinary_single_sku_spec_phrasing(): void
    {
        // Live evidence: this over-broad regex previously rejected a real
        // ASUS ROG Strix SCAR 18 and HP OMEN 16 Max search over completely
        // ordinary single-SKU spec wording - "boost up to 5.4 GHz", "up to
        // 240Hz refresh rate" describe how one fixed component behaves, not
        // that several different components could be installed.
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => 'G835LX-XS97',
            'primary_source_url' => 'https://shop.example/g835lx',
            'sources' => [[
                'url' => 'https://shop.example/g835lx',
                'type' => 'retailer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => 'G835LX-XS97'],
                ['key' => 'cpu', 'value' => 'Intel Core Ultra 9 275HX (up to 5.4 GHz, 24 cores)'],
                ['key' => 'gpu', 'value' => 'NVIDIA GeForce RTX 5090 Laptop GPU (24 GB GDDR7, ROG Boost up to 175W)'],
                ['key' => 'refresh_rate', 'value' => 'Up to 240 Hz'],
                ['key' => 'display', 'value' => '18.0" WQXGA mini-LED, HDR support (SKU-dependent peak brightness up to ~1200 nits reported)'],
            ],
        ]);

        $this->assertSame([], $issues);
    }

    public function test_it_accepts_one_exact_retail_configuration(): void
    {
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => '82XT003GUS',
            'primary_source_url' => 'https://shop.example/82xt003gus',
            'sources' => [[
                'url' => 'https://shop.example/82xt003gus',
                'type' => 'retailer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => '82XT003GUS'],
                ['key' => 'gpu', 'value' => 'NVIDIA GeForce RTX 3050 6GB'],
                ['key' => 'ram', 'value' => '16GB DDR5-5600'],
            ],
        ]);

        $this->assertSame([], $issues);
    }
}
