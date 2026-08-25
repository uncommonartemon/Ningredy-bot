<?php

namespace Tests\Unit;

use App\Services\Products\ProductResearchResponseNormalizer;
use Tests\TestCase;

class ProductResearchResponseNormalizerTest extends TestCase
{
    public function test_it_bounds_oversized_ai_payloads_without_losing_primary_sources(): void
    {
        $primaryUrl = 'https://www.amazon.com/dp/EXACT';
        $officialUrl = 'https://www.msi.com/Desktop/EXACT';
        $sources = collect(range(1, 25))
            ->map(fn (int $index): array => [
                'title' => "Store {$index}",
                'url' => "https://store{$index}.example/product",
                'type' => 'retailer',
            ])
            ->all();
        $sources[] = ['title' => 'Official', 'url' => $officialUrl, 'type' => 'manufacturer'];
        $sources[] = ['title' => 'Amazon', 'url' => $primaryUrl, 'type' => 'marketplace'];

        $data = app(ProductResearchResponseNormalizer::class)->normalize([
            'description' => str_repeat('x', 6000),
            'confidence' => 1.5,
            'primary_source_url' => $primaryUrl,
            'official_source_url' => $officialUrl,
            'image_urls' => collect(range(1, 15))
                ->map(fn (int $index): string => "https://images.example/{$index}.jpg")
                ->all(),
            'sources' => $sources,
            'specifications' => collect(range(1, 110))
                ->map(fn (int $index): array => [
                    'key' => "Specification {$index}",
                    'name' => "Specification {$index}",
                    'value' => str_repeat('v', 2100),
                ])
                ->all(),
        ]);

        $this->assertCount(10, $data['image_urls']);
        $this->assertCount(20, $data['sources']);
        $this->assertSame($primaryUrl, $data['sources'][0]['url']);
        $this->assertSame('https://store1.example/product', $data['sources'][1]['url']);
        $this->assertSame([], $data['sources'][1]['image_urls']);
        $this->assertCount(100, $data['specifications']);
        $this->assertSame('specification_1', $data['specifications'][0]['key']);
        $this->assertSame(5000, mb_strlen($data['description']));
        $this->assertSame(2000, mb_strlen($data['specifications'][0]['value']));
        $this->assertSame(1.0, $data['confidence']);
    }

    public function test_it_removes_malformed_urls_and_incomplete_rows(): void
    {
        $data = app(ProductResearchResponseNormalizer::class)->normalize([
            'image_urls' => [
                'javascript:alert(1)',
                'https://images.example/good.jpg',
                'https://images.example/good.jpg',
            ],
            'sources' => [
                ['title' => 'Broken', 'url' => 'file:///secret', 'type' => 'retailer'],
                ['title' => 'Good', 'url' => 'https://shop.example/item', 'type' => 'retailer'],
            ],
            'specifications' => [
                ['key' => 'CPU Model', 'name' => 'CPU', 'value' => 'Intel'],
                ['key' => 'empty', 'name' => '', 'value' => 'ignored'],
            ],
        ]);

        $this->assertSame(['https://images.example/good.jpg'], $data['image_urls']);
        $this->assertCount(1, $data['sources']);
        $this->assertSame('cpu_model', $data['specifications'][0]['key']);
        $this->assertCount(1, $data['specifications']);
    }

    public function test_it_preserves_the_exact_regional_product_page_url(): void
    {
        $data = app(ProductResearchResponseNormalizer::class)->normalize([
            'primary_source_url' => 'https://www.amazon.ca/dp/B0BS4BP8FB',
            'sources' => [[
                'title' => 'Amazon Canada listing',
                'url' => 'https://www.amazon.ca/Aspire-Laptop/dp/B0BS4BP8FB?ref_=ast_sto_dp',
                'type' => 'marketplace',
                'image_urls' => [],
            ]],
        ]);

        $this->assertSame('https://www.amazon.ca/dp/B0BS4BP8FB', $data['primary_source_url']);
        $this->assertSame('https://www.amazon.ca/Aspire-Laptop/dp/B0BS4BP8FB?ref_=ast_sto_dp', $data['sources'][0]['url']);
    }
}
