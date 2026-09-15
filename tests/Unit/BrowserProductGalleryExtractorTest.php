<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\BrowserProductImageTransferStore;
use App\Services\Products\HostReputation;
use App\Services\Products\ProductGalleryRecipeProof;
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

    public function test_two_frame_gallery_does_not_retrain_for_a_three_photo_publication_minimum(): void
    {
        config(['product-images.browser_fallback.enabled' => true]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $recipe = ProductGalleryRecipe::create(['domain' => 'two.example', 'path_pattern' => '*',
            'status' => 'active', 'failure_count' => 0, 'recipe' => [
                'collect_selectors' => ['#photos img'], 'gallery_present' => true, 'content_confirmed_product' => true,
            ]]);
        $urls = ['https://cdn.example/a.jpg', 'https://cdn.example/b.jpg'];
        $browser = $this->extractorReturning(['images' => $urls, 'diagnostics' => [
            'observed_gallery_count' => 2, 'validated_candidates' => 2,
        ]]);
        $this->assertSame($urls, $browser->extract('https://two.example/b', context: ['minimum_verified_images' => 3]));
        $this->assertSame(0, $recipe->fresh()->failure_count);
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame('https://two.example/b',
            app(ProductGalleryRecipeProof::class)->provenUrl($recipe, 'https://two.example/a'));
    }

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
            // The wording says which of the two situations this is - a domain
            // with no recipe at all, or one whose recipes did not fit this page -
            // and either way Vision-first must not train.
            fn (array $event): bool => str_contains($event[1], 'ещё нет AI-рецепта')
                && str_contains($event[1], 'без обучения Playwright'),
        ));
    }

    public function test_unfinished_training_frames_do_not_borrow_an_active_recipes_trust(): void
    {
        config(['product-images.browser_fallback.enabled' => true]);
        $urls = ['https://cdn.example/a.jpg', 'https://cdn.example/b.jpg', 'https://cdn.example/c.jpg'];
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturnUsing(function () use ($urls) {
            $recipe = ProductGalleryRecipe::create([
                'domain' => 'reserve.example', 'path_pattern' => '*', 'status' => 'active',
                'recipe' => ['gallery_present' => true, 'content_confirmed_product' => true],
            ]);
            $recipe->versions()->create([
                'domain' => 'reserve.example', 'product_url' => 'https://reserve.example/product',
                'trigger' => 'automatic_failure', 'status' => 'deferred', 'result' => [],
                'provider' => 'openai', 'model' => 'test-model',
            ]);

            return $urls;
        });
        $browser = app(BrowserProductGalleryExtractor::class);
        $this->assertSame($urls, $browser->extract('https://reserve.example/product'));
        foreach ($urls as $url) {
            $this->assertFalse($browser->isConfirmedGalleryImage($url));
            $this->assertTrue($browser->isPartialGalleryImage($url));
        }
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
        return new class(app(AiSettings::class), app(ProductSearchTimeBudget::class), app(ProductGalleryRecipeResultValidator::class), app(ProductSourceAttemptRecorder::class), app(BrowserProductImageTransferStore::class), app(ProductGalleryRecipeRouter::class), app(HostReputation::class), $result) extends BrowserProductGalleryExtractor
        {
            public function __construct(
                AiSettings $settings,
                ProductSearchTimeBudget $timeBudget,
                ProductGalleryRecipeResultValidator $resultValidator,
                ProductSourceAttemptRecorder $attempts,
                BrowserProductImageTransferStore $transfers,
                ProductGalleryRecipeRouter $recipeRouter,
                HostReputation $reputation,
                private readonly array $fakeResult,
            ) {
                parent::__construct($settings, $timeBudget, $resultValidator, $attempts, $transfers, $recipeRouter, $reputation);
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

    public function test_normal_search_sends_crash_to_diagnosis_without_trying_other_recipes_or_declaring_mismatch(): void
    {
        config(['product-images.browser_fallback.enabled' => true]);
        $recipe = ProductGalleryRecipe::create([
            'domain' => 'recovery.example', 'path_pattern' => '*', 'status' => 'active',
            'success_count' => 2, 'failure_count' => 0,
            'recipe' => ['gallery_present' => true, 'content_confirmed_product' => true,
                'collect_selectors' => ['.gallery img']],
        ]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()
            ->andReturnUsing(function (...$arguments) use ($recipe): array {
                $this->assertSame('execution_recovery', $arguments[1]);
                $feedback = $arguments['repairFrom'] ?? $arguments[9];
                $this->assertSame('execution_recovery', $feedback['mode']);
                $this->assertSame('browser_timeout', $feedback['failure_kind']);
                $this->assertSame(0, $recipe->fresh()->failure_count);

                return [];
            });
        $events = [];
        $this->extractorReturning([
            'images' => [],
            // The page died before selectors could be meaningfully observed.
            'learned_recipe' => ['collect_selectors' => [], 'actions' => []],
            'failure_kind' => 'browser_timeout', 'diagnostics' => ['partial' => true],
        ])->extract('https://recovery.example/product', debug: function ($level, $message) use (&$events): void {
            $events[] = $message;
        });
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame(0, $recipe->fresh()->failure_count);
        $this->assertStringNotContainsString('перестал давать', implode(' ', $events));
        $this->assertStringNotContainsString('без LLM проверяю', implode(' ', $events));
    }

    public function test_a_browser_execution_failure_does_not_damage_recipe_compatibility_health(): void
    {
        // A browser crash or an interrupted execution says nothing about
        // whether the recipe's own selectors still fit the page - it means
        // the attempt to find out did not finish. It must not be counted the
        // same as a genuine mismatch on the counter that decides whether
        // Playwright stays enabled for the whole domain.
        config()->set('product-images.browser_fallback.enabled', true);
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'browser-crash.example',
            'path_pattern' => '*',
            'status' => 'active',
            'success_count' => 4,
            'failure_count' => 1,
            'recipe' => [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 5,
                'collect_selectors' => ['.product-gallery img'],
                'actions' => [],
            ],
        ]);

        $images = $this->extractorReturning([
            'images' => ['https://cdn.example/partial-before-crash.jpg'],
            'learned_recipe' => [
                'collect_selectors' => ['.product-gallery img'],
                'thumbnail_selectors' => [],
                'actions' => [],
            ],
            'diagnostics' => ['validated_candidates' => 1],
            'failure_kind' => 'browser_timeout',
        ])->extract(
            'https://browser-crash.example/product/current-model',
            10,
            activeRecipeOnly: true,
        );

        $recipe->refresh();
        $this->assertSame(['https://cdn.example/partial-before-crash.jpg'], $images, 'Whatever was found before the failure is preserved.');
        $this->assertSame(1, $recipe->failure_count, 'A technical execution failure must not increment the compatibility counter.');
        $this->assertSame(4, $recipe->success_count);
        $this->assertSame('browser_timeout', $recipe->last_failure_kind);
        $this->assertStringContainsString('Not counted as a recipe mismatch', (string) $recipe->last_error);
        $this->assertSame('active', $recipe->status, 'The recipe stays active - this was not evidence against it.');
    }

    public function test_an_active_recipe_trains_once_and_is_then_reused_on_a_different_product_untrained(): void
    {
        // The live 2026-09-10 run trained a fresh recipe on microless.com for
        // one Lenovo draft; it never exercised what a SAVED, already-active
        // recipe does for a second, different product on that same shop -
        // repeating the same product would not prove that either. This does:
        // a recipe already active from an earlier product is applied to a
        // completely different one, and the training agent is never asked.
        config()->set('product-images.browser_fallback.enabled', true);
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'reusable-shop.example',
            'path_pattern' => '*',
            'status' => 'active',
            'success_count' => 3,
            'recipe' => [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'collect_selectors' => ['.product-gallery img'],
                'actions' => [],
            ],
        ]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $secondProductImages = [
            'https://cdn.example/second-product-front.jpg',
            'https://cdn.example/second-product-side.jpg',
            'https://cdn.example/second-product-detail.jpg',
        ];
        $extractor = $this->extractorReturning([
            'images' => $secondProductImages,
            'learned_recipe' => [
                'collect_selectors' => ['.product-gallery img'],
                'thumbnail_selectors' => [],
                'actions' => [],
            ],
            'diagnostics' => ['validated_candidates' => 3, 'observed_gallery_count' => 3],
        ]);

        $images = $extractor->extract(
            'https://reusable-shop.example/products/a-completely-different-second-laptop',
            10,
        );

        $this->assertSame($secondProductImages, $images);
        $this->assertSame(
            4,
            $recipe->fresh()->success_count,
            'Reuse on a new product increments the same shop recipe - it is not a retrain, and no new recipe row is created.',
        );
        $this->assertDatabaseCount('product_gallery_recipes', 1);
    }
}
