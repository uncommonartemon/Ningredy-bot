<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageStorage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A family landing page carries every colourway at once.
 *
 * Live on a Surface Laptop in Platinum: the same hero shot existed on the page
 * as ...-platinum-fy26.jpg and ...-ocean-fy26.jpg, and nothing between the page
 * and the download limit knew which one the card was for. It happened to pick
 * the right one. Nothing made it.
 */
class VariantAwareCandidateOrderTest extends TestCase
{
    public function test_the_colour_the_product_actually_is_comes_first(): void
    {
        $ordered = $this->rank([
            'https://images.example/msft-surface-laptop-screen-sizes-13-ocean-fy26.jpg',
            'https://images.example/msft-surface-laptop-screen-sizes-13-platinum-fy26.jpg',
        ], 'Platinum');

        $this->assertStringContainsString('platinum', $ordered[0]);
    }

    public function test_a_colour_nothing_mentions_leaves_the_order_alone(): void
    {
        // The signal is absent, and inventing one would be worse than none:
        // the existing quality ranking still decides.
        $urls = [
            'https://images.example/laptop-hero-large.jpg',
            'https://images.example/laptop-hero-thumb.jpg',
        ];

        $this->assertSame($this->rank($urls, 'Graphite'), $this->rank($urls, null));
    }

    public function test_a_colour_word_inside_another_word_does_not_count(): void
    {
        // "blue" must not match "bluetooth", which appears on accessory shots
        // of half the laptops on the market.
        $method = new ReflectionMethod(ProductImageStorage::class, 'candidateMatchesVariant');

        $this->assertFalse($method->invoke(null, 'https://images.example/laptop-bluetooth-detail.jpg', 'Blue'));
        $this->assertTrue($method->invoke(null, 'https://images.example/laptop-blue-front.jpg', 'Blue'));
    }

    public function test_a_short_colour_token_is_ignored(): void
    {
        // Two and three letter tokens match somewhere in almost any URL, and a
        // wrong preference is worse than none.
        $method = new ReflectionMethod(ProductImageStorage::class, 'candidateMatchesVariant');

        $this->assertFalse($method->invoke(null, 'https://images.example/red-laptop.jpg', 'Red'));
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function rank(array $urls, ?string $variant): array
    {
        $method = new ReflectionMethod(ProductImageStorage::class, 'cleanUrls');

        return $method->invoke(app(ProductImageStorage::class), $urls, $variant);
    }
}
