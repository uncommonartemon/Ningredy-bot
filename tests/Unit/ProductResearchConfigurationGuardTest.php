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
