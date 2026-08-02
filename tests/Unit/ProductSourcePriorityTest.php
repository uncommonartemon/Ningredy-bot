<?php

namespace Tests\Unit;

use App\Services\Products\ProductSourcePriority;
use Tests\TestCase;

class ProductSourcePriorityTest extends TestCase
{
    public function test_unknown_source_types_keep_their_input_order(): void
    {
        $priority = app(ProductSourcePriority::class);
        $sources = [
            ['title' => 'Review', 'url' => 'https://example.com/review', 'type' => 'review'],
            ['title' => 'Apple UA', 'url' => 'https://www.apple.com/ua/iphone-17/', 'type' => 'manufacturer'],
            ['title' => 'Store', 'url' => 'https://store.example.com/iphone-17', 'type' => 'retailer'],
            ['title' => 'Amazon', 'url' => 'https://www.amazon.com/dp/EXAMPLE', 'type' => 'marketplace'],
            ['title' => 'Apple US', 'url' => 'https://www.apple.com/iphone-17/', 'type' => 'manufacturer'],
        ];

        $this->assertSame(
            array_column($sources, 'title'),
            array_column($priority->sortSources($sources, 'Apple'), 'title'),
        );
    }

    public function test_unknown_image_hosts_keep_their_input_order(): void
    {
        $priority = app(ProductSourcePriority::class);
        $urls = [
            'https://upload.wikimedia.org/product.jpg',
            'https://m.media-amazon.com/images/I/product.jpg',
            'https://www.apple.com/ua/images/product.jpg',
            'https://www.apple.com/v/iphone-17/images/product.jpg',
        ];

        $this->assertSame($urls, $priority->sortUrls($urls, 'Apple'));
    }
}
