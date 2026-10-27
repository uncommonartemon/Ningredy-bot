<?php

namespace Tests\Feature;

use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductPageIdentityEvidence;
use App\Services\Products\ProductSourceIdentityGate;
use App\Services\Products\ProductSourceIdentityJudge;
use App\Services\Products\ProductSourcePageRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserIdentityEvidencePropagationTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.34/product';

    private const IMAGE = 'https://93.184.216.34/gallery.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(AiSettings::class)->shouldReceive('galleryBrowserMode')
            ->andReturn(AiSettings::GALLERY_BROWSER_AUTO);
        $this->mock(ProductSourcePageRules::class)->shouldReceive('activeRuleFor')->andReturnNull();
    }

    public function test_rendered_heading_reaches_final_identity_check_and_each_image_context(): void
    {
        $observation = $this->observation();
        $this->browser($observation, [self::IMAGE]);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([self::IMAGE], $resolver->resolve([[
            'url' => self::PAGE, 'title' => 'Old search result WRONG-999',
        ]]));

        $context = $resolver->sourceContextForImage(self::IMAGE);
        $this->assertSame($resolver->sourceContextForPage(self::PAGE), $context);
        $this->assertSame('ASUS ROG Zephyrus G14 | Shop', $context['title']);
        $this->assertStringContainsString('GA403UV-NEBULA021W', $context['_preflight_identity_evidence']);
        $this->assertStringNotContainsString('WRONG-999', $context['_preflight_identity_evidence']);
        $this->mock(ProductSourceIdentityJudge::class)->shouldNotReceive('judge');
        $draft = new ProductDraft([
            'title' => 'ASUS ROG Zephyrus G14 GA403UV-NEBULA021W',
            'model' => 'GA403UV-NEBULA021W',
            'specifications' => [['key' => 'sku', 'value' => 'GA403UV-NEBULA021W']],
        ]);
        $this->assertSame('confirmed', app(ProductSourceIdentityGate::class)->assess($draft, $context, null));
    }

    public function test_zero_photos_still_preserve_rendered_title_heading_and_redirect_identity(): void
    {
        $actual = 'https://93.184.216.34/actual-product';
        $observation = $this->observation();
        $observation['product_page_url'] = $actual;
        $observation['scout']['title'] = 'Actual GA403UV-NEBULA021W';
        $this->browser($observation, []);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([], $resolver->resolve([['url' => self::PAGE, 'title' => 'Old search WRONG-999']]));

        $context = $resolver->sourceContextForPage(self::PAGE);
        $this->assertSame($actual, $context['url']);
        $this->assertSame('Actual GA403UV-NEBULA021W', $context['title']);
        $this->assertStringContainsString('GA403UV-NEBULA021W', $context['_preflight_identity_evidence']);
        $this->assertStringNotContainsString('WRONG-999', $context['_preflight_identity_evidence']);
        $this->assertSame($context, $resolver->sourceContextForPage($actual));
    }

    public function test_empty_rendered_identity_does_not_borrow_static_or_search_confirmation(): void
    {
        $this->browser(['product_page_url' => self::PAGE, 'scout' => []], []);
        $resolver = app(ProductImageResolver::class);
        $resolver->resolve([['url' => self::PAGE, 'title' => 'Old search WRONG-999']]);

        $context = $resolver->sourceContextForPage(self::PAGE);
        $this->assertNull($context['title']);
        $this->assertSame('', $context['_preflight_identity_evidence']);
    }

    public function test_rendered_identifiers_and_headings_precede_long_descriptive_text(): void
    {
        $observation = $this->observation();
        $observation['scout']['specification_text'] = str_repeat('Unrelated page navigation ', 1000);
        $source = ProductPageIdentityEvidence::fromBrowserObservation(self::PAGE, $observation);

        $this->assertStringContainsString('GA403UV-NEBULA021W', mb_substr($source['_preflight_identity_evidence'], 0, 2000));
        $this->assertStringContainsString('90NR0I02-M00460', mb_substr($source['_preflight_identity_evidence'], 0, 2000));
        $this->assertSame(self::PAGE, $source['url']);
    }

    public function test_photo_applicability_and_differences_survive_browser_observation_to_each_image(): void
    {
        $observation = $this->observation();
        $photoAssessment = ['verdict' => 'compatible', 'may_inspect_gallery' => true,
            'differences' => ['Keyboard region differs'], 'evidence' => ['Same chassis'], 'reason' => 'Compatible photos.'];
        $assessment = ['purpose' => 'photos', 'verdict' => 'compatible',
            'product_draft_id' => 77, 'telegram_update_id' => null,
            'source' => ProductPageIdentityEvidence::fromBrowserObservation(self::PAGE, $observation),
            '_photo_applicability' => $photoAssessment];
        $this->browser($observation, [self::IMAGE], $assessment);
        $resolver = app(ProductImageResolver::class);

        $this->assertSame([self::IMAGE], $resolver->resolve([[
            'url' => self::PAGE, '_product_draft_id' => 77,
        ]]));

        $context = $resolver->sourceContextForImage(self::IMAGE);
        $this->assertSame($context, $resolver->sourceContextForPage(self::PAGE));
        $this->assertSame('photos', $context['_observed_identity']['purpose']);
        $this->assertSame($photoAssessment, $context['_photo_applicability']);
        $this->assertStringContainsString('GA403UV-NEBULA021W', $context['_preflight_identity_evidence']);
    }

    private function observation(): array
    {
        return [
            'product_page_url' => self::PAGE,
            'product_identity' => ['name' => 'ASUS ROG Zephyrus G14', 'identifiers' => []],
            'specification_text' => 'OLED screen, 16 GB RAM.',
            'scout' => [
                'final_url' => self::PAGE,
                'title' => 'ASUS ROG Zephyrus G14 | Shop',
                'product_headings' => ['ASUS ROG Zephyrus G14 GA403UV-NEBULA021W P/N 90NR0I02-M00460'],
                'specification_text' => 'OLED screen, 16 GB RAM.',
            ],
        ];
    }

    public function test_the_pages_own_pictures_lead_when_the_browser_brings_a_slider_of_other_products(): void
    {
        // Live #223: the browser returned twenty accessory thumbnails and the static
        // own pictures came after them; the download cap never reached them.
        $own = 'https://93.184.216.34/assets/n47231/product_image/Steam_Blue_3.jpeg';
        $sibling = 'https://93.184.216.34/assets/n47231/product_image/Steam_Blue_1.jpeg';
        $accessories = ['https://93.184.216.34/assets/77/product_image/dock.jpeg', 'https://93.184.216.34/assets/78/product_image/mouse.jpeg'];
        Http::fake([self::PAGE => Http::response('<html><head><meta property="og:image" content="'.$own.'"></head>'
            .'<body><img src="'.$accessories[0].'"><img src="'.$sibling.'"></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $observation = $this->observation();
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn($accessories);
        $browser->shouldReceive('lastSpecificationText')->andReturn(null);
        $browser->shouldReceive('lastProductPageUrl')->andReturn(null);
        $browser->shouldReceive('lastProductIdentity')->andReturn(null);
        $browser->shouldReceive('lastSourceIdentityAssessment')->andReturn(null);
        $browser->shouldReceive('exportObservation')->andReturn($observation);
        $confirmed = false;
        $browser->shouldReceive('isConfirmedGalleryImage')->andReturnUsing(function () use (&$confirmed): bool {
            return $confirmed;
        });

        $images = app(ProductImageResolver::class)->resolve([['url' => self::PAGE, 'title' => 'Swift 14 AI']]);

        $this->assertSame([$own, $sibling, ...$accessories], $images);

        // A confirmed recipe gallery keeps the lead.
        $confirmed = true;
        $browser->shouldReceive('extract')->once()->andReturn($accessories);
        $images = app(ProductImageResolver::class)->resolve([['url' => self::PAGE, 'title' => 'Swift 14 AI']]);

        $this->assertSame([...$accessories, $own, $sibling], $images);
    }

    private function browser(array $observation, array $images, ?array $assessment = null): void
    {
        Http::fake([self::PAGE => Http::response(
            '<html><title>Stale HTTP WRONG-999</title><h1>WRONG-999</h1></html>',
            200, ['Content-Type' => 'text/html'],
        )]);
        $browser = $this->mock(BrowserProductGalleryExtractor::class);
        $browser->shouldReceive('extract')->once()->andReturn($images);
        $browser->shouldReceive('lastSpecificationText')->andReturn($observation['specification_text'] ?? null);
        $browser->shouldReceive('lastProductPageUrl')->andReturn($observation['product_page_url'] ?? null);
        $browser->shouldReceive('lastProductIdentity')->andReturn($observation['product_identity'] ?? null);
        $browser->shouldReceive('lastSourceIdentityAssessment')->andReturn($assessment);
        $browser->shouldReceive('exportObservation')->once()->andReturn($observation);
    }
}
