<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\ProductGalleryRecipe;
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
                        ],
                        'diagnostics' => ['dom_candidates' => 2],
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
        $this->assertSame(7, $version->result['validation']['expected']);
        $this->assertSame(2, $version->result['validation']['extracted']);
    }

    public function test_preflight_skips_recipe_training_when_static_gallery_is_sufficient(): void
    {
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
}
