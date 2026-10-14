<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BrowserGalleryObservationTest extends TestCase
{
    private function browser(): BrowserProductGalleryExtractor
    {
        return (new ReflectionClass(BrowserProductGalleryExtractor::class))->newInstanceWithoutConstructor();
    }

    private function invoke(BrowserProductGalleryExtractor $browser, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionClass($browser))->getMethod($method)->invoke($browser, ...$arguments);
    }

    public function test_a_fresh_confirmed_execution_can_replace_an_unknown_identity(): void
    {
        $browser = $this->browser();
        $url = 'https://cdn.example/frame.jpg';
        $this->invoke($browser, 'noteIdentityUnconfirmed', ['images' => [$url], 'diagnostics' => ['identity_unconfirmed' => true]], null);
        $this->invoke($browser, 'rememberConfirmedGalleryImages', [$url]);
        $this->assertFalse($browser->isConfirmedGalleryImage($url));

        $this->invoke($browser, 'noteIdentityUnconfirmed', ['images' => [$url], 'diagnostics' => ['identity_unconfirmed' => false]], null);
        $this->invoke($browser, 'rememberConfirmedGalleryImages', [$url]);
        $this->assertTrue($browser->isConfirmedGalleryImage($url));
    }

    public function test_partial_execution_cannot_keep_an_older_confirmed_badge(): void
    {
        $browser = $this->browser();
        $url = 'https://cdn.example/frame.jpg';
        $reserve = 'https://cdn.example/reserve.jpg';
        $this->invoke($browser, 'rememberConfirmedGalleryImages', [$url, $reserve]);
        $this->invoke($browser, 'noteIdentityUnconfirmed', ['images' => [$url]], null);
        $this->invoke($browser, 'rememberPartialGalleryImages', [$url]);

        $this->assertFalse($browser->isConfirmedGalleryImage($url));
        $this->assertTrue($browser->isPartialGalleryImage($url));
        $this->assertTrue($browser->isConfirmedGalleryImage($reserve));
    }

    public function test_distinct_browser_instances_transfer_an_empty_rejected_scout_and_its_identity(): void
    {
        $trainerBrowser = $this->browser();
        $resolverBrowser = $this->browser();
        $reflection = new ReflectionClass($trainerBrowser);
        $reflection->getProperty('hasObservation')->setValue($trainerBrowser, true);
        $reflection->getProperty('lastProductPageUrl')->setValue($trainerBrowser, 'https://shop.example/wrong-product');
        $reflection->getProperty('lastProductIdentity')->setValue($trainerBrowser, ['sku' => 'OTHER-002']);
        $reflection->getProperty('lastSpecificationText')->setValue($trainerBrowser, 'Actual wrong product text');
        $reflection->getProperty('lastScout')->setValue($trainerBrowser, ['title' => 'Other product']);
        $assessment = ['verdict' => 'conflicting', 'source' => ['url' => 'https://shop.example/wrong-product']];
        $trainerBrowser->rememberSourceIdentityAssessment($assessment);

        $this->invoke($resolverBrowser, 'importObservation', $trainerBrowser->exportObservation());

        $this->assertNotSame($trainerBrowser, $resolverBrowser);
        $this->assertSame('https://shop.example/wrong-product', $resolverBrowser->lastProductPageUrl());
        $this->assertSame(['sku' => 'OTHER-002'], $resolverBrowser->lastProductIdentity());
        $this->assertSame('Actual wrong product text', $resolverBrowser->lastSpecificationText());
        $this->assertSame($assessment, $resolverBrowser->lastSourceIdentityAssessment());
        $this->assertSame(['title' => 'Other product'], $resolverBrowser->exportObservation()['scout']);
        $this->assertSame([], $resolverBrowser->exportObservation()['images']);
    }

    public function test_transfer_carries_current_uncertainty_and_reset_does_not_export_stale_page(): void
    {
        $trainerBrowser = $this->browser();
        $resolverBrowser = $this->browser();
        $url = 'https://cdn.example/frame.jpg';
        (new ReflectionClass($trainerBrowser))->getProperty('hasObservation')->setValue($trainerBrowser, true);
        $this->invoke($trainerBrowser, 'noteIdentityUnconfirmed', ['images' => [$url], 'diagnostics' => ['identity_unconfirmed' => true]], null);
        $this->invoke($resolverBrowser, 'rememberConfirmedGalleryImages', [$url]);
        $this->invoke($resolverBrowser, 'importObservation', $trainerBrowser->exportObservation());
        $this->invoke($resolverBrowser, 'rememberConfirmedGalleryImages', [$url]);
        $this->assertFalse($resolverBrowser->isConfirmedGalleryImage($url));

        $this->invoke($trainerBrowser, 'resetObservation');
        $this->assertSame([], $trainerBrowser->exportObservation());
    }
}
