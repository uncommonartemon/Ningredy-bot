<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\BrowserProductImageTransferStore;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeResultValidator;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BrowserProductGalleryExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_vision_first_does_not_train_when_the_domain_has_no_active_recipe(): void
    {
        config()->set('product-images.browser_fallback.enabled', true);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $events = [];

        $images = app(BrowserProductGalleryExtractor::class)->extract(
            'https://example.com/products/component',
            10,
            function (string $level, string $message) use (&$events): void {
                $events[] = [$level, $message];
            },
            activeRecipeOnly: true,
        );

        $this->assertSame([], $images);
        $this->assertTrue(collect($events)->contains(
            fn (array $event): bool => str_contains($event[1], 'нет активного рецепта')
                && str_contains($event[1], 'без обучения Playwright'),
        ));
    }

    public function test_selector_mismatch_is_detected_when_the_recipes_own_selectors_matched_nothing(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['[data-test-hook="@hpi-sox/hpstellar-pdp/gallerycarousel"] img'],
            'thumbnail_selectors' => ['button[data-test-hook^="@hpi-sox/hpstellar-pdp/gallerycarousel__thumbnail-"]'],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertTrue($mismatched);
    }

    public function test_no_mismatch_when_at_least_one_configured_selector_matched(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => ['.thumb button'],
        ], [
            'learned_recipe' => [
                'collect_selectors' => ['.gallery img'],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_no_mismatch_when_an_ordered_action_selector_matched(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['[data-gallery-image]'],
            'thumbnail_selectors' => [],
            'actions' => [[
                'kind' => 'click',
                'selector' => 'button[data-gallery]',
            ]],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
                'actions' => [[
                    'kind' => 'click',
                    'selector' => 'button[data-gallery]',
                ]],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_strict_recipe_dom_evidence_beats_empty_pre_action_snapshot(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.dialog img'],
        ], [
            'images' => ['https://cdn.example/product.jpg'],
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
                'actions' => [],
            ],
            'diagnostics' => [
                'strict_recipe' => true,
                'validated_image_evidence' => [[
                    'url' => 'https://cdn.example/product.jpg',
                    'source' => 'recipe_dom',
                ]],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_no_mismatch_when_the_recipe_configured_no_selectors_at_all(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => [],
            'thumbnail_selectors' => [],
        ], [
            'learned_recipe' => [
                'collect_selectors' => [],
                'thumbnail_selectors' => [],
            ],
        ]);

        $this->assertFalse($mismatched);
    }

    public function test_no_mismatch_when_the_script_did_not_return_a_learned_recipe(): void
    {
        $mismatched = $this->invoke([
            'collect_selectors' => ['.gallery img'],
        ], []);

        $this->assertFalse($mismatched);
    }

    public function test_saved_recipe_drops_a_broad_collect_selector_when_scoped_variant_exists(): void
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'normalizeRecipeForExecution');
        $method->setAccessible(true);

        $recipe = $method->invoke(app(BrowserProductGalleryExtractor::class), [
            'collect_selectors' => [
                'img.w-full.h-full',
                'button.w-16.h-16 img.w-full.h-full',
            ],
            'thumbnail_selectors' => ['button.w-16.h-16'],
        ]);

        $this->assertSame(
            ['button.w-16.h-16 img.w-full.h-full'],
            $recipe['collect_selectors'],
        );
        $this->assertSame(['button.w-16.h-16'], $recipe['thumbnail_selectors']);
    }

    public function test_compatible_domain_recipe_is_bound_after_strict_success_without_ai_training(): void
    {
        $recipeBody = [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 2,
            'collect_selectors' => ['.product-gallery img'],
            'actions' => [],
        ];
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/notebooks/*',
            'status' => 'active',
            'success_count' => 4,
            'recipe' => $recipeBody,
        ]);
        $result = [
            'images' => [
                'https://cdn.example/product-front.jpg',
                'https://cdn.example/product-side.jpg',
            ],
            'learned_recipe' => [
                'collect_selectors' => ['.product-gallery img'],
                'thumbnail_selectors' => [],
                'actions' => [],
            ],
            'diagnostics' => [
                'validated_candidates' => 2,
                'observed_gallery_count' => 2,
            ],
        ];

        $attempt = $this->invokeCompatibleAttempt(
            $this->extractorReturning($result),
            'https://shop.example/components/case-1',
        );

        $this->assertTrue($attempt['passed']);
        $this->assertSame($result['images'], $attempt['images']);
        $this->assertDatabaseCount('product_gallery_recipes', 1);
        $this->assertSame(5, $recipe->refresh()->success_count);
        $this->assertSame(['/components/*'], $recipe->compatible_path_patterns);
        $this->assertTrue($recipe->is(app(ProductGalleryRecipeRouter::class)->activeRecipeForUrl(
            'https://shop.example/components/case-2',
        )));
    }

    public function test_failed_unconfirmed_path_probe_does_not_damage_recipe_health(): void
    {
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'shop.example',
            'path_pattern' => '/notebooks/*',
            'status' => 'active',
            'success_count' => 4,
            'failure_count' => 2,
            'recipe' => [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 2,
                'collect_selectors' => ['.product-gallery img'],
                'actions' => [],
            ],
        ]);
        $attempt = $this->invokeCompatibleAttempt(
            $this->extractorReturning([
                'images' => ['https://cdn.example/only-one.jpg'],
                'learned_recipe' => [
                    'collect_selectors' => [],
                    'thumbnail_selectors' => [],
                    'actions' => [],
                ],
                'diagnostics' => ['validated_candidates' => 1],
            ]),
            'https://shop.example/components/case-1',
        );

        $this->assertFalse($attempt['passed']);
        $this->assertSame(4, $recipe->refresh()->success_count);
        $this->assertSame(2, $recipe->failure_count);
        $this->assertNull($recipe->compatible_path_patterns);
    }

    private function extractorReturning(array $result): BrowserProductGalleryExtractor
    {
        return new class(
            app(AiSettings::class),
            app(ProductSearchTimeBudget::class),
            app(ProductGalleryRecipeResultValidator::class),
            app(ProductSourceAttemptRecorder::class),
            app(BrowserProductImageTransferStore::class),
            app(ProductGalleryRecipeRouter::class),
            $result,
        ) extends BrowserProductGalleryExtractor
        {
            public function __construct(
                AiSettings $settings,
                ProductSearchTimeBudget $timeBudget,
                ProductGalleryRecipeResultValidator $resultValidator,
                ProductSourceAttemptRecorder $attempts,
                BrowserProductImageTransferStore $transfers,
                ProductGalleryRecipeRouter $recipeRouter,
                private readonly array $fakeResult,
            ) {
                parent::__construct($settings, $timeBudget, $resultValidator, $attempts, $transfers, $recipeRouter);
            }

            public function executeRecipe(
                string $url,
                array $recipe,
                int $limit = 20,
                ?callable $debug = null,
                ?int $telegramUpdateId = null,
                array $context = [],
            ): array {
                return $this->fakeResult;
            }
        };
    }

    /** @return array{passed: bool, images: array<int, string>} */
    private function invokeCompatibleAttempt(
        BrowserProductGalleryExtractor $extractor,
        string $url,
    ): array {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'tryCompatibleDomainRecipes');
        $method->setAccessible(true);

        return $method->invoke($extractor, $url, 10, 2, null, null, null, []);
    }

    private function invoke(array $recipe, array $result): bool
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'recipeSelectorsMismatchPage');
        $method->setAccessible(true);

        return $method->invoke(app(BrowserProductGalleryExtractor::class), $recipe, $result);
    }
}
