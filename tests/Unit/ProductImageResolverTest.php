<?php

namespace Tests\Unit;

use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductImageResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductImageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AiSettings::class)
            ->shouldReceive('galleryBrowserMode')
            ->byDefault()
            ->andReturn(AiSettings::GALLERY_BROWSER_AUTO);
    }

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
            ->with('https://93.184.216.34/javascript-product', 5, null, null, [
                'static_image_urls' => ['https://93.184.216.34/static-fallback.jpg'],
                'minimum_verified_images' => 3,
            ], false)
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
            'https://93.184.216.34/static-fallback.jpg',
        ], $images);
    }

    public function test_vision_first_only_allows_an_existing_active_browser_recipe(): void
    {
        Http::fake([
            'https://93.184.216.34/component' => Http::response(
                '<html><head><meta property=og:image content=/static.jpg></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')
            ->once()
            ->with(
                'https://93.184.216.34/component',
                5,
                null,
                null,
                ['static_image_urls' => ['https://93.184.216.34/static.jpg'], 'minimum_verified_images' => 3],
                false,
                true,
            )
            ->andReturn([]);

        $images = app(ProductImageResolver::class)->resolve(
            [['url' => 'https://93.184.216.34/component']],
            5,
            activeRecipeOnly: true,
        );

        $this->assertSame(['https://93.184.216.34/static.jpg'], $images);
    }

    public function test_it_prioritizes_full_resolution_data_big_before_thumbnail_src(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response(<<<'HTML'
                <html><body><div class='product-gallery'>
                    <img data-big='/i/products/front.jpg' src='/i/products/mini/front.jpg?w=80'>
                    <img data-big='/i/products/back.jpg' src='/i/products/mini/back.jpg?w=60'>
                </div></body></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class)->shouldReceive('extract')->andReturn([]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/product'],
        ], 2);

        $this->assertSame([
            'https://93.184.216.34/i/products/front.jpg',
            'https://93.184.216.34/i/products/back.jpg',
        ], $images);
    }

    public function test_it_never_sends_pdf_json_or_direct_images_to_playwright(): void
    {
        Http::fake([
            'https://93.184.216.34/manual' => Http::response(
                "%PDF-1.7\nproduct manual",
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://93.184.216.34/product.json' => Http::response(
                '{"images":["/one.jpg"]}',
                200,
                ['Content-Type' => 'application/json'],
            ),
            'https://93.184.216.34/hero.jpg' => Http::response(
                'image bytes',
                200,
                ['Content-Type' => 'image/jpeg'],
            ),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class)
            ->shouldNotReceive('extract');

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/manual'],
            ['url' => 'https://93.184.216.34/product.json'],
            ['url' => 'https://93.184.216.34/hero.jpg'],
        ], 5);

        $this->assertSame([], $images);
    }

    public function test_it_accepts_headerless_html_but_rejects_a_pdf_url_before_browser(): void
    {
        Http::fake([
            'https://93.184.216.34/headerless-product' => Http::response(
                '<!doctype html><html><head><meta property="og:image" content="/hero.jpg"></head></html>',
                200,
            ),
        ]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')
            ->once()
            ->with('https://93.184.216.34/headerless-product', 5, null, null, [
                'static_image_urls' => ['https://93.184.216.34/hero.jpg'],
                'minimum_verified_images' => 3,
            ], false)
            ->andReturn([]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/specification.pdf'],
            ['url' => 'https://93.184.216.34/headerless-product'],
        ], 5);

        $this->assertSame(['https://93.184.216.34/hero.jpg'], $images);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://93.184.216.34/specification.pdf');
    }

    public function test_it_does_not_use_browser_when_gallery_browser_mode_is_off(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response(
                '<html><head><meta property="og:image" content="/static.jpg"></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $settings = $this->mock(AiSettings::class);
        $settings->shouldReceive('galleryBrowserMode')
            ->andReturn(AiSettings::GALLERY_BROWSER_OFF);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldNotReceive('extract');

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/product'],
        ], 5);

        $this->assertSame(['https://93.184.216.34/static.jpg'], $images);
    }

    public function test_it_always_uses_browser_in_always_mode_even_when_static_gallery_is_full(): void
    {
        Http::fake([
            'https://93.184.216.34/product' => Http::response(
                '<html><head>'
                .'<meta property="og:image" content="/static-1.jpg">'
                .'<meta name="twitter:image" content="/static-2.jpg">'
                .'</head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $settings = $this->mock(AiSettings::class);
        $settings->shouldReceive('galleryBrowserMode')
            ->andReturn(AiSettings::GALLERY_BROWSER_ALWAYS);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')
            ->once()
            ->andReturn(['https://93.184.216.34/browser.jpg']);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/product'],
        ], 2);

        $this->assertSame([
            'https://93.184.216.34/browser.jpg',
            'https://93.184.216.34/static-1.jpg',
        ], $images);
    }

    public function test_it_marks_a_continue_shopping_page_as_blocked_instead_of_extracting_its_images(): void
    {
        config()->set('product-images.browser_fallback.enabled', true);
        Http::fake([
            'https://93.184.216.34/blocked-product' => Http::response(
                '<html><body><img src="/tiny.jpg"><p>Click the button below to continue shopping</p></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn([]);
        $events = [];

        $images = app(ProductImageResolver::class)->resolve(
            [['url' => 'https://93.184.216.34/blocked-product']],
            5,
            function (string $level, string $message) use (&$events): void {
                $events[] = [$level, $message];
            },
        );

        $this->assertSame([], $images);
        $this->assertSame('blocked', $events[0][0]);
    }

    public function test_it_extracts_amazon_full_resolution_gallery_urls_from_data_old_hires(): void
    {
        config()->set('product-images.browser_fallback.enabled', false);
        Http::fake([
            'https://93.184.216.34/amazon-product' => Http::response(
                '<ul class="desktop-media-mainView">'
                .'<li><img data-old-hires="https://m.media-amazon.com/images/I/MAIN._AC_SL1500_.jpg"></li>'
                .'<li><div data-old-hires="https://m.media-amazon.com/images/I/SECOND._AC_SL1000_.jpg"></div></li>'
                .'</ul>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/amazon-product'],
        ], 5);

        // Amazon's size-suffix (e.g. "._AC_SL1500_") is stripped by the same
        // normalization every candidate goes through before being counted or
        // downloaded (ProductImageStorage::normalizeCandidateUrl()) - the
        // suffix-free URL is Amazon's own original, full-resolution asset.
        $this->assertSame([
            'https://m.media-amazon.com/images/I/MAIN.jpg',
            'https://m.media-amazon.com/images/I/SECOND.jpg',
        ], $images);
    }

    public function test_it_collapses_two_scene7_renditions_of_one_static_photo_into_one_candidate(): void
    {
        config()->set('product-images.browser_fallback.enabled', false);
        Http::fake([
            'https://93.184.216.34/samsung-product' => Http::response(
                '<img src="https://images.samsung.com/is/image/samsung/product?%241164_776_PNG%24=">'
                .'<img src="https://images.samsung.com/is/image/samsung/product?$1164_776_PNG$">',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $images = app(ProductImageResolver::class)->resolve([
            ['url' => 'https://93.184.216.34/samsung-product'],
        ], 5);

        $this->assertSame([
            'https://images.samsung.com/is/image/samsung/product',
        ], $images);
    }
}
