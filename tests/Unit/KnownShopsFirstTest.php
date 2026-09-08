<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ordering decided before anything knocks on a shop's door.
 *
 * Five recipes had been trained by 2026-09-08 and not one had ever been reused:
 * a search reached a known shop only by accident, because the only thing that
 * knew about the recipe was the preflight - which is to say, a decision made
 * after a request had already been spent on every candidate. It follows from
 * the host alone.
 */
class KnownShopsFirstTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_we_can_already_open_goes_first(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'known.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.gallery img']],
        ]);

        $order = $this->knownFirst([
            ['url' => 'https://unknown-a.example/p/1'],
            ['url' => 'https://known.example/p/2'],
            ['url' => 'https://unknown-b.example/p/3'],
        ]);

        $this->assertSame('https://known.example/p/2', $order[0]['url']);
    }

    public function test_the_rest_keep_the_order_research_gave_them(): void
    {
        // Sorting by a key that is equal for everybody still moves things: the
        // first attempt reordered two equally-unknown shops, the search
        // finished on the one that floated up and never opened the other.
        $urls = ['https://a.example/p/1', 'https://b.example/p/2', 'https://c.example/p/3'];

        $order = $this->knownFirst(array_map(fn (string $url): array => ['url' => $url], $urls));

        $this->assertSame($urls, array_column($order, 'url'));
    }

    public function test_a_learning_recipe_is_not_a_shop_we_can_open(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'halfway.example',
            'path_pattern' => '*',
            'status' => 'learning',
        ]);

        $order = $this->knownFirst([
            ['url' => 'https://first.example/p/1'],
            ['url' => 'https://halfway.example/p/2'],
        ]);

        $this->assertSame('https://first.example/p/1', $order[0]['url']);
    }

    public function test_one_shop_contributes_at_most_two_pages(): void
    {
        // Research returned four acer.com links once and the bot visited all
        // four inside three minutes. They were never four chances - they fail
        // together - and they look exactly like a scraper. The fallback search
        // has had this rule since; the main queue, where researched cards
        // actually go, did not.
        $kept = $this->perHost([
            ['url' => 'https://shop.example/p/1'],
            ['url' => 'https://shop.example/p/2'],
            ['url' => 'https://shop.example/p/3'],
            ['url' => 'https://shop.example/p/4'],
            ['url' => 'https://other.example/p/1'],
        ]);

        $this->assertSame(
            ['https://shop.example/p/1', 'https://shop.example/p/2', 'https://other.example/p/1'],
            array_column($kept, 'url'),
        );
    }

    public function test_the_cap_counts_hosts_not_paths(): void
    {
        $kept = $this->perHost([
            ['url' => 'https://shop.example/catalog/a/1'],
            ['url' => 'https://shop.example/store/b/2'],
            ['url' => 'https://shop.example/other/c/3'],
        ]);

        $this->assertCount(2, $kept);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function perHost(array $sources): array
    {
        $method = new ReflectionMethod(ProductImageStorage::class, 'limitPerHost');

        return $method->invoke(app(ProductImageStorage::class), new Collection($sources), null)->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function knownFirst(array $sources): array
    {
        $method = new ReflectionMethod(ProductImageStorage::class, 'knownShopsFirst');

        return $method->invoke(app(ProductImageStorage::class), new Collection($sources))->all();
    }
}
