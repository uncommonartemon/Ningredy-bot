<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductGalleryRecipeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGalleryRecipeRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_urls_are_normalized_to_reusable_path_scopes(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertSame('/notebooks/*', $router->pathPatternForUrl(
            'https://rozetka.example/notebooks/lenovo-z50-70-13123?ref=search',
        ));
        $this->assertSame('/components/*', $router->pathPatternForUrl(
            'https://rozetka.example/components/case-1312',
        ));
        $this->assertSame('/ua/notebooks/*', $router->pathPatternForUrl(
            'https://rozetka.example/ua/notebooks/another-laptop',
        ));
        $this->assertSame('/catalog/*/*', $router->pathPatternForUrl(
            'https://rozetka.example/catalog/123456/lenovo-z50',
        ));
    }

    public function test_an_id_in_the_middle_of_the_path_does_not_give_every_product_its_own_scope(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        // Live consequence before this: four separate recipes for one shop, one
        // per product, each starting its training from nothing.
        $this->assertSame(
            $router->pathPatternForUrl('https://shop.example/c/product/1218008-REG/apple_mc7a4ll_a.html'),
            $router->pathPatternForUrl('https://shop.example/c/product/1876268-REG/asus_rog_zephyrus.html'),
        );
        $this->assertSame('/c/product/*/*', $router->pathPatternForUrl(
            'https://shop.example/c/product/1876268-REG/asus_rog_zephyrus.html',
        ));
        $this->assertSame('/dp/*/*', $router->pathPatternForUrl(
            'https://shop.example/dp/B0ABCDE123/ref=sr_1_1',
        ));
    }

    public function test_a_product_name_written_into_the_path_is_not_a_page_type(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        // Live case (2026-09-03): this shop already had a working recipe, the
        // probe ran it without an LLM, and it still paid for a second training
        // - because the next laptop's name made the path look like a page type
        // nobody had ever seen.
        $this->assertSame(
            $router->pathPatternForUrl('https://shop.example/product/lenovo-legion-pro-7-16iax10h-ai-pc-16-intel-core-ultra-9-275hx-3/8377744'),
            $router->pathPatternForUrl('https://shop.example/product/predator-helios-neo-16-ai-phn16-73-773d-16-gaming-notebook-wqxg/8500067'),
        );
        $this->assertSame('/product/*/*', $router->pathPatternForUrl(
            'https://shop.example/product/predator-helios-neo-16-ai-phn16-73-773d-16-gaming-notebook-wqxg/8500067',
        ));
    }

    public function test_a_short_taxonomy_segment_keeps_its_own_scope(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        // The bar for "this is a product name" has to stay above the length of
        // a category node, or two genuinely different layouts share one recipe
        // and both break. These two are live, working scopes.
        $this->assertSame('/en-us/shop/dell-laptops/xps-13-laptop/spd/*', $router->pathPatternForUrl(
            'https://shop.example/en-us/shop/dell-laptops/xps-13-laptop/spd/xps-13-9350',
        ));
        $this->assertSame('/gaming-laptops/razer-blade-18/*', $router->pathPatternForUrl(
            'https://shop.example/gaming-laptops/razer-blade-18/rz09-05299',
        ));
    }

    public function test_a_readable_path_word_is_not_mistaken_for_an_id(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        // Recognising ids too eagerly is the opposite failure: it would merge
        // genuinely different page types onto one recipe.
        $this->assertSame('/en-us/shop/dell-laptops/xps-13-laptop/spd/*', $router->pathPatternForUrl(
            'https://shop.example/en-us/shop/dell-laptops/xps-13-laptop/spd/xps-13-9350',
        ));
        $this->assertSame('/gaming-laptops/razer-blade-18/*', $router->pathPatternForUrl(
            'https://shop.example/gaming-laptops/razer-blade-18/rz09-05299',
        ));
    }

    public function test_one_domain_can_keep_separate_recipes_for_separate_paths(): void
    {
        $router = app(ProductGalleryRecipeRouter::class);

        $notebook = $router->recipeForTraining(
            'https://rozetka.example/notebooks/lenovo-z50-70-13123',
        );
        $samePath = $router->recipeForTraining(
            'https://rozetka.example/notebooks/asus-vivobook-999',
        );
        $component = $router->recipeForTraining(
            'https://rozetka.example/components/case-1312',
        );

        $this->assertTrue($notebook->is($samePath));
        $this->assertFalse($notebook->is($component));
        $this->assertSame('/notebooks/*', $notebook->path_pattern);
        $this->assertSame('/components/*', $component->path_pattern);
        $this->assertDatabaseCount('product_gallery_recipes', 2);
    }

    public function test_exact_path_recipe_wins_and_other_domain_recipe_is_only_familiarity(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'rozetka.example',
            'path_pattern' => '/notebooks/*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.notebook-gallery img']],
        ]);
        $component = ProductGalleryRecipe::query()->create([
            'domain' => 'rozetka.example',
            'path_pattern' => '/components/*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.component-gallery img']],
        ]);
        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertTrue($component->is($router->activeRecipeForUrl(
            'https://rozetka.example/components/case-999',
        )));
        $this->assertNull($router->recipeForUrl(
            'https://rozetka.example/mobile-phones/phone-999',
        ));
        $this->assertTrue($router->domainHasActiveRecipe(
            'https://rozetka.example/mobile-phones/phone-999',
        ));
    }

    public function test_legacy_domain_recipe_is_a_fallback_until_exact_path_exists(): void
    {
        $legacy = ProductGalleryRecipe::query()->create([
            'domain' => 'legacy.example',
            'path_pattern' => '*',
            'status' => 'active',
        ]);
        $router = app(ProductGalleryRecipeRouter::class);

        $this->assertTrue($legacy->is($router->recipeForUrl(
            'https://legacy.example/notebooks/model-1',
        )));

        $exact = $router->recipeForTraining('https://legacy.example/notebooks/model-1');
        $this->assertFalse($legacy->is($exact));
        $this->assertTrue($exact->is($router->recipeForUrl(
            'https://legacy.example/notebooks/model-2',
        )));
    }

    public function test_a_confirmed_new_path_is_bound_to_the_existing_recipe_without_copying_it(): void
    {
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'rozetka.example',
            'path_pattern' => '/notebooks/*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.product-gallery img']],
        ]);
        $router = app(ProductGalleryRecipeRouter::class);

        $router->bindCompatiblePath(
            $recipe,
            'https://rozetka.example/components/case-1312',
        );

        $this->assertDatabaseCount('product_gallery_recipes', 1);
        $this->assertSame(
            ['/components/*'],
            $recipe->refresh()->compatible_path_patterns,
        );
        $this->assertTrue($recipe->is($router->activeRecipeForUrl(
            'https://rozetka.example/components/another-case',
        )));
    }

    public function test_compatible_candidates_are_limited_and_ranked_by_proven_success(): void
    {
        foreach ([2, 12, 5, 9] as $index => $successCount) {
            ProductGalleryRecipe::query()->create([
                'domain' => 'shop.example',
                'path_pattern' => '/family-'.$index.'/*',
                'status' => 'active',
                'success_count' => $successCount,
            ]);
        }

        $candidates = app(ProductGalleryRecipeRouter::class)->compatibleCandidatesForUrl(
            'https://shop.example/new-family/product-1',
        );

        $this->assertCount(3, $candidates);
        $this->assertSame([12, 9, 5], $candidates->pluck('success_count')->all());
    }
}
