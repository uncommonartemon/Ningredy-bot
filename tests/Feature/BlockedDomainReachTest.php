<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedDomainReachTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blocked_domain_stays_blocked_on_the_paths_that_already_have_a_recipe(): void
    {
        // The bypass was exactly backwards: the shop kept being used on the
        // pages it had been trained on, which are the pages the bot actually
        // visits. domainIsBlocked() asked recipeForUrl(), and that falls back
        // to the domain-wide row only when no exact recipe exists - so the
        // trained path answered for itself and said "not blocked".
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/product/*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.gallery img']],
            'source_blocked' => false,
        ]);

        app(ProductGalleryRecipeRouter::class)->blockDomain('shop.example', 'operator');

        $this->assertTrue(app(ProductGalleryRecipeRouter::class)
            ->domainIsBlocked('https://shop.example/product/laptop-x1'));
        $this->assertContains('shop.example', app(ProductSourcePriority::class)->blockedDomains());
    }

    public function test_a_ban_is_one_row_and_lifting_it_leaves_the_paths_as_they_were(): void
    {
        // Marking every path row as well reads as thorough and destroys state:
        // a path blocked earlier for its own failures loses its own reason, and
        // lifting the shop's ban later would silently release it too. The
        // domain-scoped row is the whole fact, and every check reads it.
        $router = app(ProductGalleryRecipeRouter::class);
        $failedOnItsOwn = ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/clearance/*',
            'status' => 'active',
            'recipe' => [],
            'source_blocked' => true,
            'source_block_reason' => 'Recipe failed twice on this path.',
        ]);
        $working = ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/p/*',
            'status' => 'active',
            'recipe' => [],
            'source_blocked' => false,
        ]);

        $router->blockDomain('shop.example', 'operator');

        $this->assertTrue($router->domainIsBlocked('https://shop.example/p/laptop'));
        $this->assertSame('Recipe failed twice on this path.', $failedOnItsOwn->fresh()->source_block_reason);
        $this->assertFalse($working->fresh()->source_blocked);

        $router->unblockDomain('shop.example');

        $this->assertFalse($router->domainIsBlocked('https://shop.example/p/laptop'));
        $this->assertTrue(
            $failedOnItsOwn->fresh()->source_blocked,
            'Lifting the shop ban is not a verdict on a path that blocked itself.',
        );
    }

    public function test_a_working_wildcard_recipe_survives_a_temporary_ban(): void
    {
        // A domain-wide recipe is a real trained recipe, and the ban used to
        // disable it on the way in and never restore it on the way out - a
        // temporary ban permanently cost a working recipe.
        $router = app(ProductGalleryRecipeRouter::class);
        $wildcard = ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.gallery img']],
            'source_blocked' => false,
            'success_count' => 5,
        ]);

        $router->blockDomain('shop.example', 'operator');
        $router->unblockDomain('shop.example');

        $this->assertSame('active', $wildcard->fresh()->status);
        $this->assertSame(['collect_selectors' => ['.gallery img']], $wildcard->fresh()->recipe);
        $this->assertFalse($router->domainIsBlocked('https://shop.example/p/laptop'));
    }

    public function test_one_blocked_path_still_does_not_take_the_shop_down_with_it(): void
    {
        // The other half of the rule, unchanged: an automatic failure on a
        // single path is not a verdict on the shop.
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/clearance/*',
            'status' => 'active',
            'recipe' => [],
            'source_blocked' => true,
        ]);
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/product/*',
            'status' => 'active',
            'recipe' => [],
            'source_blocked' => false,
        ]);

        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertTrue($router->domainIsBlocked('https://shop.example/clearance/laptop'));
        $this->assertFalse($router->domainIsBlocked('https://shop.example/product/laptop'));
        $this->assertNotContains('shop.example', app(ProductSourcePriority::class)->blockedDomains());
    }
}
