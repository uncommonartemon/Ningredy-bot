<?php

namespace Tests\Unit;

use App\Models\ProductSourcePageRule;
use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSourcePageRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductImageResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(AiSettings::class)
            ->shouldReceive('galleryBrowserMode')
            ->byDefault()
            ->andReturn(AiSettings::GALLERY_BROWSER_AUTO);
        $this->mock(ProductSourcePageRules::class)
            ->shouldReceive('activeRuleFor')
            ->byDefault()
            ->andReturnNull();
    }

    public function test_full_confirmed_gallery_survives_a_small_caller_limit(): void
    {
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><title>Product</title><body>Product gallery</body></html>',
            200, ['Content-Type' => 'text/html'],
        )]);
        $frames = array_map(fn ($n) => 'https://93.184.216.34/gallery/frame-'.$n.'.jpg', range(1, 13));
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn($frames);
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);
        $result = app(ProductImageResolver::class)->resolve([['url' => 'https://93.184.216.34/product']], 4);
        $this->assertSame($frames, $result);
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
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')
            ->once()
            ->with('https://93.184.216.34/javascript-product', 5, null, null, [
                'static_image_urls' => ['https://93.184.216.34/static-fallback.jpg'],
                'minimum_verified_images' => 3,
                'category_slug' => null,
                'minimum_image_width' => null,
                'minimum_image_height' => null,
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
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')
            ->once()
            ->with(
                'https://93.184.216.34/component',
                5,
                null,
                null,
                [
                    'static_image_urls' => ['https://93.184.216.34/static.jpg'],
                    'minimum_verified_images' => 3,
                    'category_slug' => null,
                    'minimum_image_width' => null,
                    'minimum_image_height' => null,
                    'minimum_image_width' => null,
                    'minimum_image_height' => null,
                ],
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
        $this->mockBrowser()->shouldReceive('extract')->andReturn([]);

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
        $this->mockBrowser()
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
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')
            ->once()
            ->with('https://93.184.216.34/headerless-product', 5, null, null, [
                'static_image_urls' => ['https://93.184.216.34/hero.jpg'],
                'minimum_verified_images' => 3,
                'category_slug' => null,
                'minimum_image_width' => null,
                'minimum_image_height' => null,
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
        $browser = $this->mockBrowser();
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
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')
            ->once()
            ->andReturn(['https://93.184.216.34/browser.jpg']);
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturn(false);
        $browser->shouldReceive('isPartialGalleryImage')->andReturn(false);

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
        $browser = $this->mockBrowser();
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

        // The exact observed data-old-hires URLs are returned. Their Amazon
        // rendition suffixes participate only in the non-fetchable asset key.
        $this->assertSame([
            'https://m.media-amazon.com/images/I/MAIN._AC_SL1500_.jpg',
            'https://m.media-amazon.com/images/I/SECOND._AC_SL1000_.jpg',
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
            'https://images.samsung.com/is/image/samsung/product?%241164_776_PNG%24=',
        ], $images);
    }

    public function test_preflight_captures_specification_text_from_a_static_spec_table(): void
    {
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><title>Test Laptop</title><body>'
            .'<nav>Home / Laptops</nav>'
            .'<table><tr><td>RAM</td><td>32 GB</td></tr><tr><td>GPU</td><td>RTX 4060</td></tr></table>'
            .'<footer>Copyright 2026</footer>'
            .'</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $result = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product']);

        $this->assertStringContainsString('RAM', $result['specification_text']);
        $this->assertStringContainsString('32 GB', $result['specification_text']);
        $this->assertStringContainsString('RTX 4060', $result['specification_text']);
        // Stripped before capture, not just decoration on the page.
        $this->assertStringNotContainsString('Copyright', $result['specification_text']);
    }

    public function test_preflight_specification_text_survives_from_json_ld_not_just_visible_tables(): void
    {
        // Real bug (2026-09-11): every <script> was stripped before the
        // later query for application/ld+json content ran, so structured
        // data - the one place many real product pages actually state a
        // spec explicitly - was always discarded before it could be read.
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><head><script type="application/ld+json">'
            .'{"@type":"Product","name":"Test Laptop","gpu":"RTX 4090"}'
            .'</script></head><body><h1>Test Laptop</h1></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $result = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product']);

        $this->assertStringContainsString('RTX 4090', $result['specification_text']);
    }

    public function test_specification_text_keeps_one_label_and_value_per_line(): void
    {
        // Request #99: textContent glued cells into "Processor TypeCore Ultra 7"
        // and structured data arrived as raw JSON, so card assembly could only
        // quote glue - and a model quoting the readable table was refused.
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><head><script type="application/ld+json">'
            .'{"@type":"Product","name":"XPS 13","additionalProperty":[{"@type":"PropertyValue","name":"Processor Type","propertyID":"processor_type","value":"Core Ultra 7"}]}'
            .'</script></head><body><table><tr><th>Installed RAM</th><td>16 GB</td></tr><tr><th>Display Size</th><td>13.4"</td></tr></table>'
            .'<dl><dt>Weight</dt><dd>1.2 kg</dd></dl></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        )]);

        $lines = explode("\n", app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product'])['specification_text']);

        $this->assertContains('Installed RAM: 16 GB', $lines);
        $this->assertContains('Display Size: 13.4"', $lines);
        $this->assertContains('Weight: 1.2 kg', $lines);
        $this->assertContains('Processor Type: Core Ultra 7', $lines);
        $this->assertNotContains('propertyID', array_map(fn (string $line): string => explode(':', $line)[0], $lines));
    }

    public function test_preflight_specification_text_is_empty_when_the_page_never_opens(): void
    {
        Http::fake(['https://93.184.216.34/gone' => Http::response('', 404)]);

        $result = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/gone']);

        $this->assertSame('', $result['specification_text']);
    }

    public function test_structured_offer_enumerations_are_evidence_not_discarded_links(): void
    {
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><script type="application/ld+json">'.json_encode([
                '@context' => 'https://schema.org', '@type' => 'Product', 'sku' => 'AUDIT-42',
                'image' => 'https://cdn.example/photo.jpg',
                'offers' => [
                    '@type' => 'Offer', 'itemCondition' => 'https://schema.org/NewCondition',
                    'availability' => ['@id' => 'http://schema.org/OutOfStock'], 'price' => '42',
                ],
            ]).'</script></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $text = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product'])['specification_text'];

        $this->assertStringContainsString('offers.itemCondition: https://schema.org/NewCondition', $text);
        $this->assertStringContainsString('offers.availability: http://schema.org/OutOfStock', $text);
        $this->assertStringNotContainsString('cdn.example', $text);
    }

    public function test_structured_enumerations_keep_their_product_and_offer_paths(): void
    {
        $product = fn ($sku, $condition) => ['@type' => 'Product', 'sku' => $sku, 'offers' => [
            ['@type' => 'Offer', 'itemCondition' => $condition],
        ]];
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><script type="application/ld+json">'.json_encode(['@graph' => [
                $product('AUDIT-42', 'https://schema.org/UsedCondition'),
                $product('OTHER-99', 'https://schema.org/NewCondition'),
            ]]).'</script></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $text = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product'])['specification_text'];

        $this->assertStringContainsString('@graph[0].sku: AUDIT-42', $text);
        $this->assertStringContainsString('@graph[0].offers[0].itemCondition: https://schema.org/UsedCondition', $text);
        $this->assertStringContainsString('@graph[1].sku: OTHER-99', $text);
        $this->assertStringContainsString('@graph[1].offers[0].itemCondition: https://schema.org/NewCondition', $text);
    }

    public function test_structured_enum_projection_is_not_a_dictionary_of_condition_names(): void
    {
        Http::fake(['https://93.184.216.34/product' => Http::response(
            '<html><script type="application/ld+json">'.json_encode([
                '@type' => 'Product', 'energy' => 'https://schema.org/EUEnergyEfficiencyCategoryA',
                'image' => 'https://schema.org.not-a-vocabulary.example/image.jpg',
                'offers' => ['itemOffered' => ['@id' => 'https://93.184.216.34/product#item']],
            ]).'</script></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $text = app(ProductImageResolver::class)->preflightSource(['url' => 'https://93.184.216.34/product'])['specification_text'];

        $this->assertStringContainsString('energy: https://schema.org/EUEnergyEfficiencyCategoryA', $text);
        $this->assertStringContainsString('offers.itemOffered: https://93.184.216.34/product#item', $text);
        $this->assertStringNotContainsString('not-a-vocabulary', $text);
    }

    public function test_browser_identity_survives_http_403_and_an_empty_gallery(): void
    {
        $requested = 'https://93.184.216.34/requested-009IT';
        $actual = 'https://93.184.216.34/actual-008US';
        Http::fake([$requested => Http::response('', 403)]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn([]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($actual);
        $browser->shouldReceive('lastProductIdentity')->andReturn([
            'name' => 'Stealth 16 A2HWGG-008US', 'identifiers' => ['sku' => 'A2HWGG-008US'],
        ]);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([], $resolver->resolve([[
            'url' => $requested, 'title' => 'Stealth 16 A2HWGG-009IT',
            'image_urls' => ['https://93.184.216.34/seed.jpg'],
        ]]));

        $context = $resolver->sourceContextForPage($requested);
        $this->assertSame($actual, $context['url']);
        $this->assertSame($requested, $context['original_url']);
        $this->assertSame('Stealth 16 A2HWGG-008US', $context['title']);
        $this->assertStringContainsString('A2HWGG-008US', $context['_preflight_identity_evidence']);
        $this->assertStringNotContainsString('009IT', $context['_preflight_identity_evidence']);
        $this->assertSame($context, $resolver->sourceContextForPage($actual));
    }

    public function test_unknown_rendered_identity_preserves_static_candidates_without_inventing_confirmation(): void
    {
        $url = 'https://93.184.216.34/opaque-product';
        $image = 'https://93.184.216.34/static.jpg';
        Http::fake([$url => Http::response(
            '<html><title>Old HTTP A2HWGG-009IT</title><meta property="og:image" content="/static.jpg"></html>',
            200, ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn([]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($url);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([$image], $resolver->resolve([['url' => $url, 'title' => 'Old search A2HWGG-009IT']]));
        $context = $resolver->sourceContextForPage($url);
        $this->assertNull($context['title']);
        $this->assertSame('', $context['_preflight_identity_evidence']);
        $this->assertSame('', $context['_preflight_specification_text']);
        $this->assertSame($context, $resolver->sourceContextForImage($image));
    }

    public function test_per_image_context_uses_the_rendered_page_not_http_or_search_identity(): void
    {
        $requested = 'https://93.184.216.34/requested-009IT';
        $actual = 'https://93.184.216.34/actual-008US';
        $image = 'https://93.184.216.34/browser.jpg';
        Http::fake([$requested => Http::response(
            '<html><title>Old HTTP A2HWGG-009IT</title><h1>A2HWGG-009IT</h1></html>',
            200, ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn([$image]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($actual);
        $browser->shouldReceive('lastProductIdentity')->andReturn([
            'name' => 'Stealth 16 A2HWGG-008US', 'identifiers' => ['sku' => 'A2HWGG-008US'],
        ]);
        $browser->shouldReceive('lastSpecificationText')->andReturn('Actual rendered A2HWGG-008US');
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([$image], $resolver->resolve([['url' => $requested, 'title' => 'Search A2HWGG-009IT']]));
        $context = $resolver->sourceContextForImage($image);
        $this->assertSame($resolver->sourceContextForPage($requested), $context);
        $this->assertSame($actual, $context['url']);
        $this->assertStringNotContainsString('009IT', $context['_preflight_identity_evidence']);
        $this->assertSame('Actual rendered A2HWGG-008US', $context['_preflight_specification_text']);
    }

    public function test_a_skipped_repeat_does_not_reuse_old_page_or_image_context(): void
    {
        $requested = 'https://93.184.216.34/product';
        $actual = 'https://93.184.216.34/final-product';
        $image = 'https://93.184.216.34/browser.jpg';
        Http::fake([$requested => Http::response('<html>Product</html>', 200, ['Content-Type' => 'text/html'])]);
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn([$image]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($actual);
        $rules = app(ProductSourcePageRules::class);
        $rules->shouldReceive('activeRuleFor')->with($requested)->twice()->andReturn(
            null, new ProductSourcePageRule(['page_kind' => 'category', 'reason' => 'Not a product']),
        );
        $rules->shouldReceive('recordHit')->once();
        $resolver = app(ProductImageResolver::class);
        $this->assertSame([$image], $resolver->resolve([['url' => $requested]]));
        $this->assertNotNull($resolver->sourceContextForPage($requested));

        $this->assertSame([], $resolver->resolve([['url' => $requested]]));
        $this->assertNull($resolver->sourceContextForPage($requested));
        $this->assertNull($resolver->sourceContextForPage($actual));
        $this->assertNull($resolver->sourceContextForImage($image));
        Http::assertSentCount(1);
    }

    public function test_actual_html_identity_does_not_borrow_a_stale_search_title(): void
    {
        $url = 'https://93.184.216.34/opaque-product';
        Http::fake([$url => Http::response('<html><h1>Stealth 16 A2HWGG-008US</h1></html>', 200, ['Content-Type' => 'text/html'])]);
        $browser = $this->mockBrowser();
        $browser->shouldNotReceive('extract');
        $resolver = app(ProductImageResolver::class);

        $resolver->resolve([['url' => $url, 'title' => 'Stealth 16 A2HWGG-009IT']], staticOnly: true);

        $context = $resolver->sourceContextForPage($url);
        $this->assertNull($context['title']);
        $this->assertStringContainsString('008US', $context['_preflight_identity_evidence']);
        $this->assertStringNotContainsString('009IT', $context['_preflight_identity_evidence']);
    }

    public function test_rejected_scout_assessment_and_its_exact_evidence_survive_an_empty_result(): void
    {
        $url = 'https://93.184.216.34/opaque-product';
        Http::fake([$url => Http::response('<html>Generic product</html>', 200, ['Content-Type' => 'text/html'])]);
        $assessment = [
            'verdict' => 'conflicting', 'product_draft_id' => 41, 'telegram_update_id' => null,
            'source' => ['url' => $url, 'title' => 'Rendered H1: A2HWGG-008US',
                '_preflight_identity_evidence' => 'Observed heading A2HWGG-008US'],
        ];
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->once()->andReturn([]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($url);
        $browser->shouldReceive('lastProductIdentity')->andReturn(['name' => 'Generic product', 'identifiers' => []]);
        $browser->shouldReceive('lastSourceIdentityAssessment')->andReturn($assessment);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([], $resolver->resolve([['url' => $url, ProductRequestContext::SOURCE_KEY => 41]]));

        $context = $resolver->sourceContextForPage($url);
        $this->assertSame($assessment, $context['_observed_identity']);
        $this->assertSame('Rendered H1: A2HWGG-008US', $context['title']);
        $this->assertSame('Observed heading A2HWGG-008US', $context['_preflight_identity_evidence']);
    }

    public function test_assessment_from_a_different_draft_or_run_cannot_replace_current_page_evidence(): void
    {
        $url = 'https://93.184.216.34/opaque-product';
        Http::fake([$url => Http::response('<html>Generic product</html>', 200, ['Content-Type' => 'text/html'])]);
        $base = ['verdict' => 'conflicting', 'product_draft_id' => 41, 'telegram_update_id' => null,
            'source' => ['url' => $url, 'title' => 'WRONG-CONTEXT', '_preflight_identity_evidence' => 'WRONG-CONTEXT']];
        $browser = $this->mockBrowser();
        $browser->shouldReceive('extract')->twice()->andReturn([]);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($url);
        $browser->shouldReceive('lastProductIdentity')->andReturn(['name' => 'Current product', 'identifiers' => []]);
        $browser->shouldReceive('lastSourceIdentityAssessment')->twice()->andReturn(
            [...$base, 'product_draft_id' => 42], [...$base, 'telegram_update_id' => 900],
        );
        $resolver = app(ProductImageResolver::class);

        foreach (range(1, 2) as $attempt) {
            $resolver->resolve([['url' => $url, ProductRequestContext::SOURCE_KEY => 41]]);
            $context = $resolver->sourceContextForPage($url);
            $this->assertArrayNotHasKey('_observed_identity', $context);
            $this->assertSame('Current product', $context['title']);
            $this->assertStringNotContainsString('WRONG-CONTEXT', $context['_preflight_identity_evidence']);
        }
    }

    public function test_every_browser_entry_clears_an_earlier_source_assessment_before_early_return(): void
    {
        config(['product-images.browser_fallback.enabled' => false]);
        $browser = app(BrowserProductGalleryExtractor::class);
        $assessment = ['verdict' => 'conflicting', 'product_draft_id' => 41, 'telegram_update_id' => 50, 'source' => []];

        foreach (['extract', 'scout', 'scoutAfterOpening', 'executeRecipe'] as $method) {
            $browser->rememberSourceIdentityAssessment($assessment);
            $this->assertSame($assessment, $browser->lastSourceIdentityAssessment());
            match ($method) {
                'scoutAfterOpening' => $browser->scoutAfterOpening('https://93.184.216.34/product', '.specs'),
                'executeRecipe' => $browser->executeRecipe('https://93.184.216.34/product', []),
                default => $browser->{$method}('https://93.184.216.34/product'),
            };
            $this->assertNull($browser->lastSourceIdentityAssessment(), $method);
        }
    }

    /**
     * lastSpecificationText() is a same-call side channel read unconditionally
     * whenever the browser extractor runs at all, independent of whether it
     * found any images - every test mocking extract() needs it stubbed too,
     * or Mockery's strict mock throws on the unexpected call.
     */
    private function mockBrowser(): MockInterface
    {
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('lastSpecificationText')->andReturn(null)->byDefault();
        $browser->shouldReceive('lastProductPageUrl')->andReturn(null)->byDefault();
        $browser->shouldReceive('lastProductIdentity')->andReturn(null)->byDefault();
        $browser->shouldReceive('lastSourceIdentityAssessment')->andReturn(null)->byDefault();
        $browser->shouldReceive('exportObservation')->andReturn([])->byDefault();

        return $browser;
    }
}
