<?php

namespace Tests\Unit;

use App\Services\Products\ProductSourcePriority;
use Tests\TestCase;

class ProductSourcePriorityTest extends TestCase
{
    public function test_it_prioritizes_amazon_then_official_sources(): void
    {
        $priority = app(ProductSourcePriority::class);
        $sources = [
            ['title' => 'Review', 'url' => 'https://example.com/review', 'type' => 'review'],
            ['title' => 'Apple UA', 'url' => 'https://www.apple.com/ua/iphone-17/', 'type' => 'manufacturer'],
            ['title' => 'Store', 'url' => 'https://store.example.com/iphone-17', 'type' => 'retailer'],
            ['title' => 'Amazon', 'url' => 'https://www.amazon.com/dp/EXAMPLE', 'type' => 'marketplace'],
            ['title' => 'Apple US', 'url' => 'https://www.apple.com/iphone-17/', 'type' => 'manufacturer'],
        ];

        $sorted = $priority->sortSources($sources, 'Apple');

        $this->assertSame([
            'Amazon',
            'Apple US',
            'Apple UA',
            'Store',
            'Review',
        ], array_column($sorted, 'title'));
    }

    public function test_it_applies_the_same_priority_to_image_urls(): void
    {
        $priority = app(ProductSourcePriority::class);
        $sources = [
            ['title' => 'Apple US', 'url' => 'https://www.apple.com/iphone-17/', 'type' => 'manufacturer'],
        ];
        $urls = [
            'https://upload.wikimedia.org/product.jpg',
            'https://m.media-amazon.com/images/I/product.jpg',
            'https://www.apple.com/ua/images/product.jpg',
            'https://www.apple.com/v/iphone-17/images/product.jpg',
        ];

        $this->assertSame([
            'https://m.media-amazon.com/images/I/product.jpg',
            'https://www.apple.com/v/iphone-17/images/product.jpg',
            'https://www.apple.com/ua/images/product.jpg',
            'https://upload.wikimedia.org/product.jpg',
        ], $priority->sortUrls($urls, 'Apple', $sources));
    }
}
