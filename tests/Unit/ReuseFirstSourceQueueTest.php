<?php

namespace Tests\Unit;

use App\Services\Products\ProductSourcePriority;
use Tests\TestCase;

class ReuseFirstSourceQueueTest extends TestCase
{
    public function test_a_solved_domain_further_down_the_ranking_is_tried_before_any_training(): void
    {
        // The expensive shape: the ranking puts a shop nobody has a recipe for
        // first, so training used to start there while the third source was an
        // already-solved domain that could have answered for free.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://new-shop.test/p/1'),
            $this->source('https://another-new-shop.test/p/2'),
            $this->source('https://solved.test/p/3', activeRecipe: true),
        ]);

        $this->assertSame([
            'https://solved.test/p/3',
            'https://new-shop.test/p/1',
            'https://another-new-shop.test/p/2',
            'https://solved.test/p/3',
        ], array_column($queue, 'url'));
        // Pulled forward it may only reuse; its second turn may train.
        $this->assertTrue($queue[0]['_reuse_only']);
        $this->assertFalse($queue[3]['_reuse_only']);
    }

    public function test_the_cheap_pass_keeps_the_ranking_order_among_ready_sources(): void
    {
        // Queueing only the source that had to move let a weaker third source
        // overtake an equally ready first one - the opposite of what the
        // ranking decided. The whole reuse-ready set moves, in its own order.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://best.test/p/1', activeRecipe: true),
            $this->source('https://new-shop.test/p/2'),
            $this->source('https://weaker.test/p/3', activeRecipe: true),
        ]);

        $this->assertSame([
            'https://best.test/p/1',
            'https://weaker.test/p/3',
            'https://best.test/p/1',
            'https://new-shop.test/p/2',
            'https://weaker.test/p/3',
        ], array_column($queue, 'url'));
        $this->assertSame([true, true, false, false, false], array_column($queue, '_reuse_only'));
    }

    public function test_a_recipe_on_another_path_of_the_same_domain_also_counts(): void
    {
        // A compatible recipe from the same domain is probed without an LLM,
        // so it is just as cheap as the domain's own exact recipe.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://new-shop.test/p/1'),
            $this->source('https://known.test/p/2', knownDomain: true),
        ]);

        $this->assertSame('https://known.test/p/2', $queue[0]['url']);
        $this->assertCount(3, $queue);
    }

    public function test_a_solved_domain_already_at_the_head_is_not_queued_twice(): void
    {
        // The ranking tries it first regardless, so a second entry would only
        // pay for a repeated browser run.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://solved.test/p/1', activeRecipe: true),
            $this->source('https://new-shop.test/p/2'),
        ]);

        $this->assertSame([
            'https://solved.test/p/1',
            'https://new-shop.test/p/2',
        ], array_column($queue, 'url'));
        $this->assertFalse($queue[0]['_reuse_only']);
    }

    public function test_sources_with_nothing_to_reuse_are_never_queued_twice(): void
    {
        // Staging only accepts a gallery on Playwright-confirmed frames, so a
        // source with no recipe cannot win a no-training pass at all.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://new-shop.test/p/1'),
            $this->source('https://another-new-shop.test/p/2'),
        ]);

        $this->assertCount(2, $queue);
        $this->assertSame([false, false], array_column($queue, '_reuse_only'));
    }

    public function test_a_search_that_may_not_train_walks_the_list_exactly_once(): void
    {
        // Vision-first categories never train, so there is no expensive step to
        // get in front of - duplicating entries would only cost downloads.
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            $this->source('https://new-shop.test/p/1'),
            $this->source('https://solved.test/p/2', activeRecipe: true),
        ], trainingDisabled: true);

        $this->assertCount(2, $queue);
        $this->assertSame([true, true], array_column($queue, '_reuse_only'));
    }

    /** @return array<string, mixed> */
    private function source(string $url, bool $activeRecipe = false, bool $knownDomain = false): array
    {
        return [
            'url' => $url,
            'type' => 'retailer',
            '_preflight_active_recipe' => $activeRecipe,
            '_preflight_known_recipe_domain' => $activeRecipe || $knownDomain,
        ];
    }
}
