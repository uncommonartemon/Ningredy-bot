<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductImageResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductImageResolverTest extends TestCase
{
    public function test_it_extracts_absolute_and_relative_product_metadata_images(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response(<<<'HTML'
                <html><head>
                    <meta property="og:image" content="/images/product.webp">
                    <meta name="twitter:image" content="https://93.184.216.34/images/product-2.jpg">
                </head></html>
                HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/product'],
        ]);

        $this->assertSame([
            'https://93.184.216.34/images/product.webp',
            'https://93.184.216.34/images/product-2.jpg',
        ], $images);
    }

    public function test_it_extracts_images_from_image_objects_itemprop_and_script_state(): void
    {
        Http::fake([
            'https://93.184.216.34/modern-product' => Http::response(<<<'HTML'
                <html><head>
                    <meta itemprop="image" content="/cdn/itemprop-product.webp">
                    <script type="application/ld+json">
                        {"@type":"Product","image":[{"@type":"ImageObject","url":"/cdn/jsonld-object.jpg"}]}
                    </script>
                    <script type="application/json">
                        {"gallery":["https:\/\/93.184.216.34\/cdn\/script-front.png?width=1200","https:\/\/93.184.216.34\/cdn\/brand-logo.png"]}
                    </script>
                </head></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/modern-product'],
        ], 10);

        $this->assertSame([
            'https://93.184.216.34/cdn/itemprop-product.webp',
            'https://93.184.216.34/cdn/jsonld-object.jpg',
            'https://93.184.216.34/cdn/script-front.png?width=1200',
        ], $images);
    }

    public function test_it_blocks_private_source_addresses(): void
    {
        Http::fake();

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'http://127.0.0.1/private-product'],
            ['url' => 'http://192.168.1.1/router'],
        ]);

        $this->assertSame([], $images);
        Http::assertNothingSent();
    }

    public function test_it_extracts_product_gallery_images_from_srcset_and_json_ld(): void
    {
        Http::fake([
            'https://93.184.216.34/catalog/product' => Http::response(<<<'HTML'
                <html><head>
                    <script type="application/ld+json">
                        {"@type":"Product","image":["/gallery/product-front.jpg","/gallery/product-back.jpg"]}
                    </script>
                </head><body>
                    <picture>
                        <source srcset="/gallery/product-small.webp 400w, /gallery/product-large.webp 1200w">
                        <img src="/images/placeholder.png" data-zoom-image="/gallery/product-detail.jpg">
                    </picture>
                    <img src="/assets/brand-logo.png">
                </body></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/catalog/product'],
        ], 10);

        $this->assertSame([
            'https://93.184.216.34/gallery/product-detail.jpg',
            'https://93.184.216.34/gallery/product-large.webp',
            'https://93.184.216.34/gallery/product-small.webp',
            'https://93.184.216.34/gallery/product-front.jpg',
            'https://93.184.216.34/gallery/product-back.jpg',
        ], $images);
    }

    public function test_it_uses_the_browser_fallback_when_static_html_has_too_few_gallery_images(): void
    {
        config()->set('product-images.browser_fallback.enabled', true);
        Http::fake([
            'https://93.184.216.34/javascript-product' => Http::response(
                '<html><head><meta property="og:image" content="/static-fallback.jpg"></head><body><div id="app"></div></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')
            ->once()
            ->with('https://93.184.216.34/javascript-product', 5)
            ->andReturn([
                'https://93.184.216.34/browser-product-front.jpg',
                'https://93.184.216.34/browser-product-back.jpg',
            ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/javascript-product'],
        ], 5);

        $this->assertSame([
            'https://93.184.216.34/browser-product-front.jpg',
            'https://93.184.216.34/browser-product-back.jpg',
        ], $images);
    }
}
