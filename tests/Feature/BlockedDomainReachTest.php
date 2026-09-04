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

    public function test_both_operator_buttons_write_the_same_block(): void
    {
        // One shop, two buttons, one meaning. Filament used to flip the row it
        // was showing while Telegram created the domain-wide one, so which
        // button the operator reached for decided whether the block held.
        $router = app(ProductGalleryRecipeRouter::class);
        ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/p/*',
            'status' => 'active',
            'recipe' => [],
            'source_blocked' => false,
        ]);

        $router->blockDomain('shop.example', 'operator');

        $this->assertSame(
            2,
            ProductGalleryRecipe::query()->where('domain', 'shop.example')->where('source_blocked', true)->count(),
            'Every row of the domain carries the block, including the wildcard one.',
        );

        $router->unblockDomain('shop.example');

        $this->assertSame(
            0,
            ProductGalleryRecipe::query()->where('domain', 'shop.example')->where('source_blocked', true)->count(),
        );
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
