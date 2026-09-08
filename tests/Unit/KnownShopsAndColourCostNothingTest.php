<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Two filters that run before anything knocks on a shop's door.
 *
 * Five recipes had been trained by 2026-09-08 and not one had ever been reused:
 * a search reached a known shop only by accident, because the only thing that
 * knew about the recipe was the preflight - which is to say, a decision made
 * after a request had already been spent on every candidate. It follows from
 * the host alone.
 */
class KnownShopsAndColourCostNothingTest extends TestCase
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

    public function test_a_listing_that_names_another_colour_is_dropped_unopened(): void
    {
        $kept = $this->withoutWrongColour('Gold', [
            ['url' => 'https://a.example/p/1', 'title' => 'XPS 13 9350 Silver'],
            ['url' => 'https://b.example/p/2', 'title' => 'XPS 13 9350 Gold'],
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame('https://b.example/p/2', $kept[0]['url']);
    }

    public function test_a_listing_that_names_no_colour_is_kept(): void
    {
        // Half of all listings omit it. Silence is not a contradiction, and
        // this filter may only ever remove - the colour a gallery really shows
        // is decided by Vision on frames.
        $kept = $this->withoutWrongColour('Gold', [
            ['url' => 'https://a.example/p/1', 'title' => 'Dell XPS 13 9350 i7 16GB'],
        ]);

        $this->assertCount(1, $kept);
    }

    public function test_a_compound_colour_is_not_matched_by_half_of_itself(): void
    {
        // "Rose Gold" is two colour words. Asking for gold must not keep a rose
        // gold listing on the strength of one of them.
        $kept = $this->withoutWrongColour('Gold', [
            ['url' => 'https://a.example/p/1', 'title' => 'XPS 13 Rose Gold'],
        ]);

        $this->assertCount(1, $kept, 'Rose gold names gold too, so this is not a contradiction to act on.');

        $dropped = $this->withoutWrongColour('Rose Gold', [
            ['url' => 'https://a.example/p/1', 'title' => 'XPS 13 Silver'],
        ]);

        $this->assertCount(0, $dropped);
    }

    public function test_nothing_is_dropped_when_no_colour_was_asked_for(): void
    {
        $kept = $this->withoutWrongColour('', [
            ['url' => 'https://a.example/p/1', 'title' => 'XPS 13 Silver'],
            ['url' => 'https://b.example/p/2', 'title' => 'XPS 13 Gold'],
        ]);

        $this->assertCount(2, $kept);
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

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function withoutWrongColour(string $colour, array $sources): array
    {
        $draft = new ProductDraft(['color' => $colour]);
        $method = new ReflectionMethod(ProductImageStorage::class, 'withoutContradictedColour');

        return $method->invoke(app(ProductImageStorage::class), new Collection($sources), $draft, null)->all();
    }
}
