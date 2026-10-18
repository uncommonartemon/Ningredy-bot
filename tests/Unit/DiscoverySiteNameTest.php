<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageCandidateDiscovery;
use PHPUnit\Framework\TestCase;

class DiscoverySiteNameTest extends TestCase
{
    public function test_regional_domains_of_one_network_share_a_site_name(): void
    {
        foreach (['https://allegro.pl/a', 'https://www.allegro.cz/b', 'https://shop.allegro.sk/c'] as $url) {
            $this->assertSame('allegro', ProductImageCandidateDiscovery::siteName($url), $url);
        }
        $this->assertSame('amazon', ProductImageCandidateDiscovery::siteName('https://www.amazon.co.uk/dp/X'));
        $this->assertSame('acer', ProductImageCandidateDiscovery::siteName('https://store.acer.com/p'));
        $this->assertNotSame(
            ProductImageCandidateDiscovery::siteName('https://galaxus.de/p'),
            ProductImageCandidateDiscovery::siteName('https://alza.de/p'),
        );
        $this->assertSame('', ProductImageCandidateDiscovery::siteName('not a url'));
    }
}
