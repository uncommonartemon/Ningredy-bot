<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\AppSetting;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductGalleryRecipeTrainerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'train_playwright',
            'gallery_likely' => true,
            'hidden_images_likely' => true,
            'interaction_required' => true,
            'expected_image_count' => 2,
            'evidence' => ['gallery fixture'],
            'confidence' => 0.95,
            'reason' => 'Fixture requires browser interaction.',
        ])->preventStrayPrompts();
    }

    public function test_confirmed_access_gate_immediately_disables_playwright_for_the_domain(): void
    {
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')
                ->once()
                ->andReturn([
                    'scout' => [
                        'fragments' => [],
                        'access_gate' => true,
                        'access_gate_reason' => 'captcha',
                        'rate_limited' => false,
                    ],
                ]);
        });
        $trainer = app(ProductGalleryRecipeTrainer::class);

        $trainer->train('https://blocked.example/product-one', force: true);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'blocked.example')->firstOrFail();

        $this->assertSame('disabled', $recipe->status);
        $this->assertSame('access_gate', $recipe->last_failure_kind);
        $this->assertSame(1, $recipe->consecutive_hard_blocks);
        $this->assertSame(['https://blocked.example/product-one'], $recipe->hard_block_urls);
        $this->assertNull($recipe->retry_after);

        $this->assertSame([], $trainer->train('https://blocked.example/product-two'));
    }

    public function test_gallery_control_without_fragments_reaches_recipe_training(): void
    {
        $seenPrompt = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seenPrompt): array {
            $seenPrompt = json_decode($prompt, true);

            return [
                'gallery_present' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'The same-product Gallery tab exposes three photos.',
                'pre_click_selectors' => ['a[href*="/Gallery"]'],
                'collect_selectors' => ['.gallery img'],
                'thumbnail_selectors' => [],
                'open_selectors' => [],
                'next_selectors' => [],
                'attributes' => ['src', 'data-src'],
                'max_thumbnail_clicks' => 0,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 200,
                'confidence' => 0.95,
                'reason' => 'Open the internal Gallery tab, then collect its images.',
            ];
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => [
                        '<a class="productMenu__item" href="/Laptop/Katana-17-HX-B14WX/Gallery">GALLERY</a>',
                    ],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn([
                'images' => [
                    'https://storage.example/one.webp',
                    'https://storage.example/two.webp',
                    'https://storage.example/three.webp',
                ],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertCount(3, $images);
        $this->assertStringContainsString('/Gallery', $seenPrompt['page']['interactive_controls'][0]);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'us.msi.com')->firstOrFail();
        $this->assertSame('active', $recipe->status);
        $this->assertSame(
            ['a[href*="/Gallery"]'],
            $recipe->recipe['pre_click_selectors'],
        );
    }

    public function test_repeated_browser_timeouts_disable_playwright_after_the_retry_budget(): void
    {
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')
                ->times(3)
                ->andReturn([
                    'scout' => ['fragments' => []],
                    'failure_kind' => 'browser_timeout',
                    'error' => 'Navigation timed out.',
                ]);
        });
        $trainer = app(ProductGalleryRecipeTrainer::class);

        foreach (range(1, 3) as $attempt) {
            $trainer->train("https://slow.example/product-{$attempt}", force: true);
        }

        $recipe = ProductGalleryRecipe::query()->where('domain', 'slow.example')->firstOrFail();
        $this->assertSame('disabled', $recipe->status);
        $this->assertSame('browser_timeout', $recipe->last_failure_kind);
        $this->assertSame(3, $recipe->failure_count);
        $this->assertNull($recipe->retry_after);

        $this->assertSame([], $trainer->train('https://slow.example/product-four'));
    }

    public function test_two_failed_recipe_training_sessions_disable_playwright(): void
    {
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'gallery_present' => true,
            'expected_image_count' => 2,
            'expected_count_evidence' => 'Two gallery items in the supplied DOM.',
            'pre_click_selectors' => [],
            'collect_selectors' => ['.gallery-that-never-works img'],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 100,
            'confidence' => 0.4,
            'reason' => 'Candidate recipe.',
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')
                ->twice()
                ->andReturn([
                    'scout' => [
                        'title' => 'Product',
                        'fragments' => ['<div class="gallery"></div>'],
                        'interactive_controls' => [],
                        'network_image_samples' => [],
                        'access_gate' => false,
                        'rate_limited' => false,
                    ],
                    'diagnostics' => [],
                ]);
            $mock->shouldReceive('executeRecipe')
                ->times(6)
                ->andReturn(['images' => [], 'diagnostics' => ['dom_candidates' => 0]]);
        });
        $trainer = app(ProductGalleryRecipeTrainer::class);

        $trainer->train('https://broken-gallery.example/product-one', force: true);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'broken-gallery.example')->firstOrFail();
        $this->assertSame('learning', $recipe->status);
        $this->assertSame(1, $recipe->failure_count);

        $trainer->train('https://broken-gallery.example/product-two', force: true);
        $recipe->refresh();
        $this->assertSame('disabled', $recipe->status);
        $this->assertSame('recipe_mismatch', $recipe->last_failure_kind);
        $this->assertSame(2, $recipe->failure_count);
        $this->assertNull($recipe->retry_after);

        $this->assertSame([], $trainer->train('https://broken-gallery.example/product-three'));
    }

    public function test_rate_limit_only_pauses_playwright(): void
    {
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')
                ->once()
                ->andReturn([
                    'scout' => [
                        'fragments' => [],
                        'access_gate' => false,
                        'rate_limited' => true,
                    ],
                ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://busy.example/product',
            force: true,
        );

        $recipe = ProductGalleryRecipe::query()->where('domain', 'busy.example')->firstOrFail();
        $this->assertSame('learning', $recipe->status);
        $this->assertSame('rate_limited', $recipe->last_failure_kind);
        $this->assertTrue($recipe->retry_after->isFuture());
    }

    public function test_ai_gets_one_corrective_attempt_after_a_recipe_returns_no_gallery(): void
    {
        $aiCalls = 0;
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$aiCalls, &$prompts): array {
            $aiCalls++;
            $prompts[] = $prompt;

            return [
                'gallery_present' => true,
                'expected_image_count' => 2,
                'expected_count_evidence' => 'Two image items in the gallery.',
                'pre_click_selectors' => [],
                'collect_selectors' => [$aiCalls === 1 ? '.failed-gallery img' : '[data-gallery] img'],
                'thumbnail_selectors' => [],
                'open_selectors' => [],
                'next_selectors' => [],
                'attributes' => ['src', 'data-full'],
                'max_thumbnail_clicks' => 0,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 150,
                'confidence' => 0.9,
                'reason' => $aiCalls === 1 ? 'First attempt.' : 'Corrected from execution feedback.',
            ];
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product',
                    'fragments' => ['<div data-gallery><img data-full="/one.jpg"></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')
                ->twice()
                ->andReturn(
                    [
                        'images' => [],
                        'diagnostics' => ['dom_candidates' => 0],
                        'post_interaction_scout' => [
                            'fragments' => ['<div data-second-layer><button data-thumb></button></div>'],
                        ],
                        'action_trace' => [[
                            'action' => 'pre_click',
                            'selector' => '[data-open-gallery]',
                            'clicked' => true,
                            'changed' => true,
                        ]],
                    ],
                    [
                        'images' => [
                            'https://cdn.example/one.jpg',
                            'https://cdn.example/two.jpg',
                            'https://cdn.example/three.jpg',
                        ],
                        'diagnostics' => ['dom_candidates' => 3],
                    ],
                );
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
        );

        $this->assertSame(2, $aiCalls);
        $this->assertStringContainsString('data-second-layer', $prompts[1]);
        $this->assertStringContainsString('action_trace', $prompts[1]);
        $this->assertSame([
            'https://cdn.example/one.jpg',
            'https://cdn.example/two.jpg',
            'https://cdn.example/three.jpg',
        ], $images);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'shop.example')->firstOrFail();
        $this->assertSame('active', $recipe->status);
        $this->assertSame(['[data-gallery] img'], $recipe->recipe['collect_selectors']);
        $this->assertSame(0.9, $recipe->recipe['confidence']);
        $this->assertSame('Corrected from execution feedback.', $recipe->recipe['reason']);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_url' => 'https://shop.example/product',
            'actor' => 'ai',
            'phase' => 'gallery_preflight',
        ]);
        $this->assertDatabaseHas('product_source_attempts', [
            'product_url' => 'https://shop.example/product',
            'actor' => 'playwright',
            'action' => 'pre_click',
            'decision' => 'dom_changed',
        ]);
    }

    public function test_operator_hint_is_included_in_the_training_prompt(): void
    {
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [
                'gallery_present' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'n/a',
                'pre_click_selectors' => [],
                'collect_selectors' => ['.gallery img'],
                'thumbnail_selectors' => [],
                'open_selectors' => [],
                'next_selectors' => [],
                'attributes' => ['src'],
                'max_thumbnail_clicks' => 0,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 100,
                'confidence' => 0.5,
                'reason' => 'Candidate recipe.',
            ];
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product',
                    'fragments' => ['<div class="gallery"></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn([
                'images' => ['https://cdn.example/one.jpg', 'https://cdn.example/two.jpg', 'https://cdn.example/three.jpg'],
                'diagnostics' => [],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://hinted.example/product',
            force: true,
            userHint: 'На странице таблица с разными моделями.',
        );

        $this->assertNotEmpty($prompts);
        $decoded = json_decode($prompts[0], true);
        $this->assertSame('На странице таблица с разными моделями.', $decoded['operator_hint']);
    }

    public function test_retraining_reuses_the_callers_already_measured_old_recipe_result_instead_of_rerunning_it(): void
    {
        // Real production bug (2026-08-04): when a saved recipe is re-verified
        // and found broken, the trainer used to independently re-execute that
        // same (already-failing) recipe against the same URL a second time just
        // to seed the partial-success fallback - a non-deterministic re-run of
        // the very thing that just failed, which could spuriously report
        // "success" with stale/mismatched images even though every real
        // training round found nothing. The caller already has that
        // measurement; the trainer should reuse it, not re-derive it.
        ProductGalleryRecipe::query()->create([
            'domain' => 'legacy.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.old-gallery img'], 'confidence' => 0.8, 'reason' => 'Old.'],
        ]);
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'gallery_present' => true,
            'expected_image_count' => 2,
            'expected_count_evidence' => 'n/a',
            'pre_click_selectors' => [],
            'collect_selectors' => ['.still-broken img'],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 100,
            'confidence' => 0.5,
            'reason' => 'Still nothing.',
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'fragments' => ['<div class="old-gallery"><img src="/one.jpg"></div>'],
                    'interactive_controls' => [], 'network_image_samples' => [],
                    'access_gate' => false, 'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            // Exactly one call per training round (3) - not 4, which would mean
            // the old, already-failing recipe got silently re-run a second time.
            $mock->shouldReceive('executeRecipe')->times(3)->andReturn([
                'images' => [],
                'diagnostics' => [],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://legacy.example/product',
            'automatic_failure',
            force: true,
            previousRecipeImages: ['https://cdn.example/stale-but-real.jpg'],
        );

        $this->assertSame(['https://cdn.example/stale-but-real.jpg'], $images);
        $version = ProductGalleryRecipeVersion::query()->where('domain', 'legacy.example')->latest('id')->firstOrFail();
        $this->assertSame('partial', $version->status);
    }

    public function test_recipe_is_rejected_when_it_extracts_only_two_of_seven_observed_images(): void
    {
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'gallery_present' => true,
            'expected_image_count' => 7,
            'expected_count_evidence' => 'Alt text reports Thumbnail 1 of 7.',
            'pre_click_selectors' => [],
            'collect_selectors' => ['[data-imageurl]'],
            'thumbnail_selectors' => ['button[data-imageurl]'],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['data-imageurl'],
            'max_thumbnail_clicks' => 7,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 250,
            'confidence' => 0.97,
            'reason' => 'Seven product image thumbnails are present.',
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product',
                    'fragments' => ['<button data-imageurl=/image/one>Thumbnail 1 of 7</button>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'observed_gallery_count' => 7,
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => ['observed_gallery_count' => 7],
            ]);
            $mock->shouldReceive('executeRecipe')->times(3)->andReturn([
                'images' => [
                    'https://cdn.example/image/one',
                    'https://cdn.example/image/two',
                ],
                'diagnostics' => [
                    'observed_gallery_count' => 7,
                    'validated_candidates' => 2,
                ],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
        );

        $this->assertSame([
            'https://cdn.example/image/one',
            'https://cdn.example/image/two',
        ], $images);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'shop.example')->firstOrFail();
        $this->assertSame('learning', $recipe->status);
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('partial', $version->status);
        $this->assertFalse($version->result['validation']['passed']);
        $this->assertSame(3, $version->result['validation']['expected']);
        $this->assertSame(2, $version->result['validation']['extracted']);
    }

    public function test_preflight_skips_recipe_training_when_static_gallery_is_sufficient_and_playwright_first_is_disabled(): void
    {
        // gallery_prefer_playwright_first defaults to true (see the next
        // test) - this covers the opt-out path, where a static_sufficient
        // verdict is still trusted as-is.
        AppSetting::put('ai.gallery_prefer_playwright_first', '0');
        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'static_sufficient',
            'gallery_likely' => true,
            'hidden_images_likely' => false,
            'interaction_required' => false,
            'expected_image_count' => 2,
            'evidence' => ['Two full-size static URLs.'],
            'confidence' => 0.98,
            'reason' => 'No browser interaction is needed.',
        ])->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'fragments' => ['<div data-gallery></div>'],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });
        $static = [
            'https://cdn.example/front.jpg',
            'https://cdn.example/back.jpg',
        ];

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
            context: ['static_image_urls' => $static],
        );

        $this->assertSame($static, $images);
        $version = ProductGalleryRecipe::query()->where('domain', 'shop.example')
            ->firstOrFail()->versions()->latest('id')->firstOrFail();
        $this->assertSame('skipped', $version->status);
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
    }

    public function test_static_sufficient_with_a_real_gallery_trains_a_recipe_by_default(): void
    {
        // Real production decision (2026-08-06): the preflight's photo-count
        // estimate can be inflated by thumbnails/CDN size-variants (smarty.cz
        // case: predicted 8, Vision-verified 3 real photos). By default,
        // finding a real gallery (gallery_likely) trains a proper, reusable,
        // Vision-verified recipe instead of trusting that raw estimate - even
        // though the preflight said static_sufficient.
        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'static_sufficient',
            'gallery_likely' => true,
            'hidden_images_likely' => false,
            'interaction_required' => false,
            'expected_image_count' => 8,
            'evidence' => ['Eight img src references.'],
            'confidence' => 0.97,
            'reason' => 'Looks like enough static photos are already listed.',
        ])->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake([[
            'gallery_present' => true,
            'expected_image_count' => 3,
            'expected_count_evidence' => 'Three real product photos.',
            'pre_click_selectors' => [],
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 100,
            'confidence' => 0.9,
            'reason' => 'Trained recipe collects the three real photos.',
        ]])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'fragments' => ['<div data-gallery></div>'],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn([
                'images' => [
                    'https://cdn.example/one.jpg',
                    'https://cdn.example/two.jpg',
                    'https://cdn.example/three.jpg',
                ],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
            context: ['static_image_urls' => ['https://cdn.example/front.jpg', 'https://cdn.example/back.jpg']],
        );

        $this->assertSame([
            'https://cdn.example/one.jpg',
            'https://cdn.example/two.jpg',
            'https://cdn.example/three.jpg',
        ], $images);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'shop.example')->firstOrFail();
        $this->assertSame('active', $recipe->status);
    }

    public function test_preflight_does_not_count_two_scene7_renditions_of_one_photo_as_distinct(): void
    {
        $seenStaticUrls = null;
        ProductGalleryPreflightAgent::fake(function (string $prompt) use (&$seenStaticUrls): array {
            $seenStaticUrls = json_decode($prompt, true)['static_image_urls'] ?? null;

            return [
                'decision' => 'no_gallery',
                'gallery_likely' => false,
                'hidden_images_likely' => false,
                'interaction_required' => false,
                'expected_image_count' => 0,
                'evidence' => [],
                'confidence' => 0.9,
                'reason' => 'Only one distinct photo, requested at two sizes.',
            ];
        })->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'fragments' => ['<div data-gallery></div>'],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
            context: ['static_image_urls' => [
                'https://images.samsung.com/is/image/samsung/product?%241164_776_PNG%24=',
                'https://images.samsung.com/is/image/samsung/product?$1164_776_PNG$',
            ]],
        );

        $this->assertSame(
            ['https://images.samsung.com/is/image/samsung/product'],
            $seenStaticUrls,
        );
    }
}
