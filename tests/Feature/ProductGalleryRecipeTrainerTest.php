<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Tools\AbandonGalleryTrainingAttempt;
use App\Ai\Tools\FlagDomainRecipeNote;
use App\Models\AppSetting;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceDomain;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\GalleryTrainingAbandonSignal;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\Data\ToolCall;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductGalleryRecipeTrainerTest extends TestCase
{
    use RefreshDatabase;

    public function test_observed_image_urls_accepts_null_before_the_first_feedback_round(): void
    {
        $method = new \ReflectionMethod(ProductGalleryRecipeTrainer::class, 'observedImageUrls');
        $method->setAccessible(true);

        $this->assertSame(
            ['https://exact.example/first.jpg', 'https://exact.example/second.jpg'],
            $method->invoke(
                app(ProductGalleryRecipeTrainer::class),
                ['https://exact.example/first.jpg'],
                null,
                ['https://exact.example/second.jpg'],
            ),
        );
    }

    public function test_an_overlong_action_purpose_is_truncated_instead_of_rejecting_the_recipe(): void
    {
        $method = new \ReflectionMethod(ProductGalleryRecipeTrainer::class, 'validateRecipe');
        $method->setAccessible(true);
        $recipe = $method->invoke(app(ProductGalleryRecipeTrainer::class), [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 4,
            'expected_count_evidence' => 'Four visible gallery thumbnails.',
            'actions' => [[
                'kind' => 'click',
                'selector' => '.gallery-button',
                'index' => 0,
                'limit' => 1,
                'wait_after_ms' => 100,
                'purpose' => str_repeat('long explanation ', 30),
            ]],
            'pre_click_selectors' => [],
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => ['.thumb'],
            'open_selectors' => ['.gallery-button'],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 4,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 100,
            'confidence' => 0.9,
            'reason' => 'Usable gallery recipe.',
        ]);

        $this->assertSame(200, mb_strlen($recipe['actions'][0]['purpose']));
    }

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

    public function test_agent_requires_opening_the_expanded_viewer_before_thumbnail_traversal(): void
    {
        $instructions = (string) (new ProductGalleryRecipeTrainerAgent)->instructions();

        $this->assertStringContainsString(
            'opening its dedicated media viewer is the first gallery',
            $instructions,
        );
        $this->assertStringContainsString(
            'Page-level thumbnails are only a fallback when the viewer cannot be opened.',
            $instructions,
        );
        $this->assertStringContainsString(
            'Every candidate recipe is executed from a fresh page load at the original URL',
            $instructions,
        );
        $this->assertStringContainsString(
            'Treat that observation only as diagnostic evidence',
            $instructions,
        );
        // The contract used to promise that no cookies survived a round, which
        // stopped being true the moment sessions were kept per host so shops
        // would stop answering with a challenge. An agent told the wrong thing
        // writes the wrong recipe - one that depends on dismissing a consent
        // wall that will not be there next time.
        $this->assertStringContainsString(
            "The site's own cookies are the one exception",
            $instructions,
        );
        $this->assertStringContainsString(
            'never make the recipe depend on dismissing one',
            $instructions,
        );
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

    public function test_the_page_screenshot_is_attached_with_a_media_type(): void
    {
        // The media type is not decoration. Without it the data URL is
        // malformed and the provider rejects the entire request with a 400 -
        // which is what happened on 2026-09-04, on every round of every
        // training, from the moment the screenshot was added.
        $seenAttachments = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt, $attachments) use (&$seenAttachments): array {
            $seenAttachments = $attachments;

            return $this->workingRecipe();
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
                'screenshot' => 'fake-png-bytes',
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn([
                'images' => [
                    'https://storage.example/one.webp',
                    'https://storage.example/two.webp',
                    'https://storage.example/three.webp',
                ],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertCount(1, $seenAttachments);
        $this->assertSame('image/png', $seenAttachments->first()->mimeType());
    }

    /** @return array<string, mixed> */
    private function workingRecipe(): array
    {
        return [
            'gallery_present' => true,
            'content_confirmed_product' => true,
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
    }

    public function test_gallery_control_without_fragments_reaches_recipe_training(): void
    {
        $seenPrompt = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seenPrompt): array {
            $seenPrompt = json_decode($prompt, true);

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
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

    public function test_scout_current_src_alias_is_normalized_without_spending_a_correction_round(): void
    {
        $promptCount = 0;
        $seenRecipe = null;

        ProductGalleryRecipeTrainerAgent::fake(function () use (&$promptCount): array {
            $promptCount++;

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'Three distinct product slides are present.',
                'pre_click_selectors' => [],
                'collect_selectors' => ['.product-gallery img'],
                'thumbnail_selectors' => [],
                'open_selectors' => [],
                'next_selectors' => [],
                'attributes' => ['current_src', 'src'],
                'max_thumbnail_clicks' => 0,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 150,
                'confidence' => 0.98,
                'reason' => 'Rendered images already expose both product photos.',
            ];
        })->preventStrayPrompts();

        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$seenRecipe): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product gallery',
                    'fragments' => ['<section class="product-gallery"><img></section>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()
                ->andReturnUsing(function (string $url, array $recipe) use (&$seenRecipe): array {
                    $seenRecipe = $recipe;

                    return ['images' => [
                        'https://cdn.example/one.jpg',
                        'https://cdn.example/two.jpg',
                        'https://cdn.example/three.jpg',
                    ]];
                });
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
        );

        $this->assertCount(3, $images);
        $this->assertSame(1, $promptCount);
        $this->assertSame(['src'], $seenRecipe['attributes']);
    }

    public function test_broad_collect_selector_is_removed_when_gallery_scoped_variant_exists(): void
    {
        $seenRecipe = null;

        ProductGalleryRecipeTrainerAgent::fake([[
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 6,
            'expected_count_evidence' => 'Six product thumbnails are inside the gallery controls.',
            'pre_click_selectors' => [],
            'collect_selectors' => [
                'img.w-full.h-full',
                'button.w-16.h-16 img.w-full.h-full',
            ],
            'thumbnail_selectors' => ['button.w-16.h-16'],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 6,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 150,
            'confidence' => 0.96,
            'reason' => 'The nested selector is scoped to the product gallery.',
        ]])->preventStrayPrompts();

        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$seenRecipe): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product gallery',
                    'fragments' => ['<button class=w-16><img class=w-full></button>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()
                ->andReturnUsing(function (string $url, array $recipe) use (&$seenRecipe): array {
                    $seenRecipe = $recipe;

                    return ['images' => [
                        'https://cdn.example/one.jpg',
                        'https://cdn.example/two.jpg',
                        'https://cdn.example/three.jpg',
                        'https://cdn.example/four.jpg',
                        'https://cdn.example/five.jpg',
                        'https://cdn.example/six.jpg',
                    ]];
                });
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
        );

        $this->assertCount(6, $images);
        $this->assertSame(
            ['button.w-16.h-16 img.w-full.h-full'],
            $seenRecipe['collect_selectors'],
        );
    }

    public function test_ai_can_send_an_ordered_safe_action_plan_to_playwright(): void
    {
        $seenPrompt = null;
        $seenRecipe = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seenPrompt): array {
            $seenPrompt = json_decode($prompt, true);

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'A gallery button reveals three thumbnail controls.',
                'actions' => [
                    [
                        'kind' => 'click',
                        'selector' => 'button[data-gallery]',
                        'index' => 0,
                        'limit' => 1,
                        'wait_after_ms' => 300,
                        'purpose' => 'Open the product media viewer.',
                    ],
                    [
                        'kind' => 'click_each',
                        'selector' => 'button[data-thumbnail]',
                        'index' => 0,
                        'limit' => 3,
                        'wait_after_ms' => 150,
                        'purpose' => 'Load every distinct product photo.',
                    ],
                ],
                'pre_click_selectors' => [],
                'collect_selectors' => ['[data-gallery-image]'],
                'thumbnail_selectors' => ['button[data-thumbnail]'],
                'open_selectors' => ['button[data-gallery]'],
                'next_selectors' => [],
                'attributes' => ['src', 'data-full'],
                'max_thumbnail_clicks' => 3,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 150,
                'confidence' => 0.97,
                'reason' => 'Use the supplied stable controls in page order.',
            ];
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$seenRecipe): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Layered product gallery',
                    'fragments' => ['<section data-gallery-image></section>'],
                    'interactive_controls' => [],
                    'action_candidates' => [[
                        'selector' => 'button[data-gallery]',
                        'selector_match_count' => 1,
                        'text' => '',
                        'aria_label' => 'Open media',
                        'within_media' => true,
                        'rect' => ['x' => 20, 'y' => 50, 'width' => 600, 'height' => 500],
                    ]],
                    'image_candidates' => [[
                        'selector' => '[data-gallery-image]',
                        'natural_width' => 1600,
                        'natural_height' => 1200,
                        'parent_control_selector' => 'button[data-gallery]',
                    ]],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()
                ->andReturnUsing(function (string $url, array $recipe) use (&$seenRecipe): array {
                    $seenRecipe = $recipe;

                    return [
                        'images' => [
                            'https://cdn.example/one.jpg',
                            'https://cdn.example/two.jpg',
                            'https://cdn.example/three.jpg',
                        ],
                        'action_trace' => [[
                            'action' => 'click',
                            'selector' => 'button[data-gallery]',
                            'action_index' => 0,
                            'clicked' => true,
                            'changed' => true,
                        ], [
                            'action' => 'click_each',
                            'selector' => 'button[data-thumbnail]',
                            'action_index' => 1,
                            'repetition' => 0,
                            'selector_match_count' => 3,
                            'clicked' => true,
                            'changed' => true,
                        ], [
                            'action' => 'click_each',
                            'selector' => 'button[data-thumbnail]',
                            'action_index' => 1,
                            'repetition' => 1,
                            'selector_match_count' => 3,
                            'clicked' => true,
                            'changed' => true,
                        ], [
                            'action' => 'click_each',
                            'selector' => 'button[data-thumbnail]',
                            'action_index' => 1,
                            'repetition' => 2,
                            'selector_match_count' => 3,
                            'clicked' => true,
                            'changed' => true,
                        ]],
                    ];
                });
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/layered-product',
            force: true,
        );

        $this->assertCount(3, $images);
        $this->assertSame('button[data-gallery]', $seenPrompt['page']['action_candidates'][0]['selector']);
        $this->assertSame('click', $seenRecipe['actions'][0]['kind']);
        $this->assertSame('click_each', $seenRecipe['actions'][1]['kind']);
        $this->assertSame(
            $seenRecipe['actions'],
            ProductGalleryRecipe::query()->where('domain', 'shop.example')->firstOrFail()->recipe['actions'],
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
            'content_confirmed_product' => true,
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
            // Every configured safety round remains available. Equal image
            // counts do not prove equal browser state or an unfixable recipe.
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

    public function test_confirmed_download_failure_needs_the_full_threshold_before_degrading(): void
    {
        // A single blip must not force a retrain - only a repeated pattern
        // (the recipe's own in-browser probe passes, but a later plain HTTP
        // download of the exact same URL keeps failing) is treated as a
        // real signal.
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'gated.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['content_confirmed_product' => true],
        ]);
        $trainer = app(ProductGalleryRecipeTrainer::class);

        $this->assertFalse($trainer->recordConfirmedDownloadFailure('gated.example'));
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame(1, $recipe->fresh()->failure_count);

        $this->assertFalse($trainer->recordConfirmedDownloadFailure('gated.example'));
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame(2, $recipe->fresh()->failure_count);

        $this->assertTrue($trainer->recordConfirmedDownloadFailure('gated.example'));
        $recipe->refresh();
        $this->assertSame('learning', $recipe->status);
        $this->assertSame('download_unreachable', $recipe->last_failure_kind);
        $this->assertSame(3, $recipe->failure_count);
    }

    public function test_confirmed_download_success_resets_the_failure_counter(): void
    {
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'flaky.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['content_confirmed_product' => true],
            'failure_count' => 2,
            'last_failure_kind' => 'download_unreachable',
            'last_failure_at' => now(),
        ]);

        app(ProductGalleryRecipeTrainer::class)->recordConfirmedDownloadSuccess('flaky.example');

        $this->assertSame(0, $recipe->fresh()->failure_count);
    }

    public function test_confirmed_download_failures_stop_degrading_after_the_cycle_cap(): void
    {
        // If retraining keeps "verifying" the recipe but real downloads
        // still fail every time afterwards, the click sequence is not the
        // problem (almost certainly a session/cookie-gated CDN) - no amount
        // of retraining fixes that, so this must stop spending AI budget on
        // it instead of retraining forever.
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'permanently-gated.example',
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['content_confirmed_product' => true],
        ]);
        $trainer = app(ProductGalleryRecipeTrainer::class);

        // Sequence detection compares last_failure_at/last_success_at, and
        // this test's own writes to both happen within milliseconds of each
        // other - freeze and step fake "now" by whole seconds so the order
        // is deterministic instead of depending on real wall-clock timing
        // surviving second-precision timestamp columns.
        Carbon::setTestNow(now());

        foreach (range(1, 3) as $cycle) {
            $this->assertFalse($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
            Carbon::setTestNow(now()->addSecond());
            $this->assertFalse($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
            Carbon::setTestNow(now()->addSecond());
            $this->assertTrue($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
            $recipe->refresh();
            $this->assertSame('learning', $recipe->status, "cycle {$cycle} should degrade");

            // Simulate a successful retrain: the recipe verifies again and
            // goes back to active with a fresh last_success_at, starting a
            // new failure sequence for the next cycle.
            Carbon::setTestNow(now()->addSecond());
            $recipe->update(['status' => 'active', 'last_success_at' => now()]);
            Carbon::setTestNow(now()->addSecond());
        }

        // The cap (3 cycles) was already hit - a 4th cycle must not degrade
        // again, however many times it fails.
        $this->assertFalse($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
        Carbon::setTestNow(now()->addSecond());
        $this->assertFalse($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
        Carbon::setTestNow(now()->addSecond());
        $this->assertFalse($trainer->recordConfirmedDownloadFailure('permanently-gated.example'));
        $recipe->refresh();
        $this->assertSame('active', $recipe->status);
        $this->assertStringContainsString('ручная проверка', (string) $recipe->last_error);

        Carbon::setTestNow();
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
                'content_confirmed_product' => true,
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

    public function test_correction_dialog_replays_prerequisites_and_survives_zero_to_zero(): void
    {
        $aiCalls = 0;
        $prompts = [];
        $executedRecipes = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$aiCalls, &$prompts): array {
            $aiCalls++;
            $prompts[] = json_decode($prompt, true);
            $traversalSelector = match ($aiCalls) {
                1 => '[data-page-thumbnail]',
                2 => '[data-wrong-modal-thumbnail]',
                default => '[data-modal-thumbnail]',
            };

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'Three product photos are exposed by the viewer.',
                'actions' => array_values(array_filter([
                    $aiCalls === 1 ? [
                        'kind' => 'click',
                        'selector' => 'button[data-gallery]',
                        'index' => 0,
                        'limit' => 1,
                        'wait_after_ms' => 100,
                        'purpose' => 'Open the product viewer.',
                    ] : null,
                    [
                        'kind' => 'click_each',
                        'selector' => $traversalSelector,
                        'index' => 0,
                        'limit' => 3,
                        'wait_after_ms' => 100,
                        'purpose' => 'Traverse every viewer thumbnail.',
                    ],
                    $aiCalls === 2 ? [
                        'kind' => 'click',
                        'selector' => 'button[data-gallery]',
                        'index' => 0,
                        'limit' => 1,
                        'wait_after_ms' => 100,
                        'purpose' => 'Open the product viewer.',
                    ] : null,
                ])),
                'pre_click_selectors' => [],
                'collect_selectors' => ['[data-active-gallery-image]'],
                'thumbnail_selectors' => [$traversalSelector],
                'open_selectors' => ['button[data-gallery]'],
                'next_selectors' => [],
                'attributes' => ['src', 'data-full'],
                'max_thumbnail_clicks' => 3,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 100,
                'confidence' => 0.95,
                'reason' => 'Use the viewer revealed by the previous execution.',
            ];
        })->preventStrayPrompts();

        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$executedRecipes): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Layered gallery',
                    'fragments' => ['<button data-gallery>Open</button>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $execution = 0;
            $mock->shouldReceive('executeRecipe')->times(3)
                ->andReturnUsing(function (string $url, array $recipe) use (&$execution, &$executedRecipes): array {
                    $execution++;
                    $executedRecipes[] = $recipe;
                    $opener = [
                        'action' => 'click',
                        'action_index' => 0,
                        'selector' => 'button[data-gallery]',
                        'selector_match_count' => 1,
                        'clicked' => true,
                        'changed' => true,
                        'expanded_gallery_visible_after' => true,
                    ];

                    if ($execution < 3) {
                        return [
                            'images' => [],
                            'diagnostics' => ['validated_candidates' => 0],
                            'action_trace' => [$opener, [
                                'action' => 'click_each',
                                'action_index' => 1,
                                'selector_match_count' => $execution === 1 ? 3 : 0,
                                'clicked' => false,
                                'changed' => false,
                                'selector_missing' => $execution === 2,
                            ]],
                            'post_interaction_scout' => [
                                'fragments' => ['<dialog><button data-modal-thumbnail></button></dialog>'],
                            ],
                        ];
                    }

                    return [
                        'images' => [
                            'https://cdn.example/one.jpg',
                            'https://cdn.example/two.jpg',
                            'https://cdn.example/three.jpg',
                        ],
                        'diagnostics' => ['validated_candidates' => 3],
                        'action_trace' => [
                            $opener,
                            ...array_map(fn (int $index): array => [
                                'action' => 'click_each',
                                'action_index' => 1,
                                'repetition' => $index,
                                'selector_match_count' => 3,
                                'clicked' => true,
                                'changed' => true,
                            ], range(0, 2)),
                        ],
                    ];
                });
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/replayable-gallery',
            force: true,
        );

        $this->assertCount(3, $images);
        $this->assertSame(3, $aiCalls);
        $this->assertSame('button[data-gallery]', $executedRecipes[1]['actions'][0]['selector']);
        $this->assertSame('[data-wrong-modal-thumbnail]', $executedRecipes[1]['actions'][1]['selector']);
        $this->assertSame('button[data-gallery]', $executedRecipes[2]['actions'][0]['selector']);
        $this->assertSame('[data-modal-thumbnail]', $executedRecipes[2]['actions'][1]['selector']);
        $this->assertSame('fresh_page_load_for_every_recipe_execution', $prompts[1]['execution_contract']['browser_state']);
        $this->assertStringContainsString('data-gallery', $prompts[1]['page']['fragments'][0]);
        $this->assertStringContainsString(
            'data-modal-thumbnail',
            $prompts[1]['previous_attempt_feedback']['previous_attempt_observation']['fragments'][0],
        );
    }

    public function test_operator_hint_is_included_in_the_training_prompt(): void
    {
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
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
        $this->assertDatabaseHas('product_source_domains', [
            'domain' => 'hinted.example',
        ]);
    }

    public function test_persistent_domain_hint_is_included_in_preflight_and_training_prompts(): void
    {
        ProductSourceDomain::query()->updateOrCreate(
            ['domain' => 'hinted.example'],
            ['agent_hint' => 'После открытия viewer нажми вложенный zoom и проверь более крупный сетевой URL.'],
        );
        $preflightPrompt = null;
        ProductGalleryPreflightAgent::fake(function (string $prompt) use (&$preflightPrompt): array {
            $preflightPrompt = json_decode($prompt, true);

            return [
                'decision' => 'train_playwright',
                'gallery_likely' => true,
                'hidden_images_likely' => true,
                'interaction_required' => true,
                'expected_image_count' => 3,
                'evidence' => ['layered gallery'],
                'confidence' => 0.95,
                'reason' => 'Нужно открыть вложенный просмотрщик.',
            ];
        })->preventStrayPrompts();
        $trainingPrompt = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$trainingPrompt): array {
            $trainingPrompt = json_decode($prompt, true);

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'Three gallery images.',
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
                'images' => [
                    'https://cdn.example/one.jpg',
                    'https://cdn.example/two.jpg',
                    'https://cdn.example/three.jpg',
                ],
                'diagnostics' => [],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://www.hinted.example/product',
            force: true,
        );

        $expected = 'После открытия viewer нажми вложенный zoom и проверь более крупный сетевой URL.';
        $this->assertSame($expected, $preflightPrompt['domain_hint']);
        $this->assertSame($expected, $trainingPrompt['domain_hint']);
        $this->assertNull($trainingPrompt['operator_hint']);
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
            'content_confirmed_product' => true,
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
            // The old, already-failing recipe must not be silently re-run to
            // seed the partial fallback. All three new training rounds remain
            // available even when their image counts are equal.
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

    public function test_partial_training_accumulates_complementary_gallery_frames_across_rounds(): void
    {
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->validRecipeResponse([
            'expected_image_count' => 5,
            'expected_count_evidence' => 'Five product gallery frames are expected.',
        ]))->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Exact product',
                    'fragments' => ['<div data-gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->times(3)->andReturn(
                [
                    'images' => [
                        'https://cdn.example/frame-2.jpg',
                        'https://cdn.example/frame-3.jpg',
                        'https://cdn.example/frame-4.jpg',
                    ],
                    'diagnostics' => ['observed_gallery_count' => 5, 'validated_candidates' => 3],
                ],
                [
                    'images' => [
                        'https://cdn.example/frame-1.jpg',
                        'https://cdn.example/frame-2.jpg',
                    ],
                    'diagnostics' => ['observed_gallery_count' => 5, 'validated_candidates' => 2],
                ],
                [
                    'images' => [
                        'https://cdn.example/frame-1.jpg',
                        'https://cdn.example/frame-2.jpg',
                    ],
                    'diagnostics' => ['observed_gallery_count' => 5, 'validated_candidates' => 2],
                ],
            );
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/exact-product',
            force: true,
        );

        $this->assertSame([
            'https://cdn.example/frame-2.jpg',
            'https://cdn.example/frame-3.jpg',
            'https://cdn.example/frame-4.jpg',
            'https://cdn.example/frame-1.jpg',
        ], $images);
        $version = ProductGalleryRecipeVersion::query()->where('domain', 'shop.example')->latest('id')->firstOrFail();
        $this->assertSame('partial', $version->status);
        $this->assertSame(4, $version->result['best_partial_count']);
    }

    public function test_recipe_is_rejected_when_it_extracts_only_two_of_seven_observed_images(): void
    {
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'gallery_present' => true,
            'content_confirmed_product' => true,
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
            // Equal image sets do not hide changed DOM/action feedback, so all
            // configured safety rounds remain available to the trainer.
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
            'content_confirmed_product' => true,
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

    public function test_failed_preflight_is_interrupted_and_keeps_observed_images_retryable(): void
    {
        ProductGalleryPreflightAgent::fake(fn (): never => throw new \RuntimeException('provider temporarily unavailable'))
            ->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'fragments' => ['<div data-gallery></div>'],
                    'network_image_samples' => ['https://cdn.example/browser.jpg'],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://shop.example/product',
            force: true,
            context: ['static_image_urls' => ['https://cdn.example/static.jpg']],
        );

        $this->assertSame([
            'https://cdn.example/browser.jpg',
            'https://cdn.example/static.jpg',
        ], $images);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'shop.example')->firstOrFail();
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('interrupted', $version->status);
        $this->assertSame('interrupted', $version->result['preflight']['decision']);
        $this->assertSame('learning', $recipe->status);
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
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
            ['https://images.samsung.com/is/image/samsung/product?%241164_776_PNG%24='],
            $seenStaticUrls,
        );
    }

    public function test_preflight_remembers_a_high_confidence_family_landing_and_skips_it_next_time(): void
    {
        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'unsuitable_page',
            'page_kind' => 'product_family_landing',
            'gallery_likely' => false,
            'hidden_images_likely' => false,
            'interaction_required' => false,
            'expected_image_count' => 0,
            'evidence' => [
                'Several configurations have separate prices and buy controls.',
                'The page contains independent feature-story carousels but no isolated product media container.',
            ],
            'confidence' => 0.97,
            'reason' => 'This is a product-family marketing landing page.',
        ])->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake()->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Laptop family',
                    'fragments' => ['<main><section data-feature-story></section></main>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $url = 'https://brand.example/laptops/model-family?region=us';
        $this->assertSame([], app(ProductGalleryRecipeTrainer::class)->train($url, force: true));
        $this->assertDatabaseHas('product_source_page_rules', [
            'domain' => 'brand.example',
            'path' => '/laptops/model-family',
            'page_kind' => 'product_family_landing',
            'active' => true,
        ]);

        $preflight = app(ProductImageResolver::class)->preflightSource(['url' => $url]);

        $this->assertTrue($preflight['blocked']);
        $this->assertSame('known_unsuitable_page', $preflight['reason']);
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
    }

    public function test_training_round_can_abandon_a_page_after_inspecting_the_dom(): void
    {
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'training_decision' => 'abandon_page',
            'page_kind' => 'editorial_marketing',
            'page_assessment_evidence' => [
                'Slide captions describe manufacturing steps rather than product views.',
                'No price, SKU, gallery counter, media thumbnails, or product-card controls surround the carousel.',
            ],
            'gallery_present' => false,
            'content_confirmed_product' => false,
            'expected_image_count' => 0,
            'expected_count_evidence' => 'No isolated product gallery.',
            'actions' => [],
            'pre_click_selectors' => [],
            'collect_selectors' => [],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => [],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 150,
            'confidence' => 0.96,
            'reason' => 'The carousel is editorial content, not a product gallery.',
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'How the laptop is made',
                    'fragments' => ['<section><h2>Machined from aluminum</h2></section>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $this->assertSame([], app(ProductGalleryRecipeTrainer::class)->train(
            'https://brand.example/stories/model',
            force: true,
        ));
        $this->assertDatabaseHas('product_source_page_rules', [
            'domain' => 'brand.example',
            'path' => '/stories/model',
            'page_kind' => 'editorial_marketing',
        ]);
        $version = ProductGalleryRecipeVersion::query()->latest('id')->firstOrFail();
        $this->assertSame('rejected', $version->status);
        $this->assertSame('abandon_page', $version->result['page_assessment']['training_decision']);
    }

    public function test_three_identical_browser_outcomes_stop_only_the_current_page(): void
    {
        AppSetting::put('ai.gallery_training_max_rounds', '10');
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 4,
            'expected_count_evidence' => 'Four items were estimated.',
            'actions' => [],
            'pre_click_selectors' => [],
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 150,
            'confidence' => 0.7,
            'reason' => 'Try the candidate gallery.',
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Unclear product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->times(3)->andReturn([
                'images' => [],
                'diagnostics' => [],
                'action_trace' => [],
                'post_interaction_scout' => [],
            ]);
        });

        $this->assertSame([], app(ProductGalleryRecipeTrainer::class)->train(
            'https://unclear.example/product',
            force: true,
        ));
        $recipe = ProductGalleryRecipe::query()->where('domain', 'unclear.example')->firstOrFail();
        $this->assertSame(1, $recipe->failure_count);
        $this->assertDatabaseMissing('product_source_page_rules', ['domain' => 'unclear.example']);
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('page_stalled', $version->result['failure_kind']);
    }

    public function test_rounds_that_keep_collecting_nothing_stop_even_when_the_dom_keeps_moving(): void
    {
        // Live case 2026-09-03: acer.com renders its gallery into a Scene7
        // canvas, so no selector can ever reach an image. Every round clicked
        // successfully and moved the DOM differently, which kept the stagnation
        // signature changing and let the session burn seven paid rounds on a
        // page that cannot be scraped by selector at all.
        AppSetting::put('ai.gallery_training_max_rounds', '10');
        $round = 0;
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->validRecipeResponse())->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$round): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Canvas viewer product page',
                    'fragments' => ['<div class=viewer><canvas></canvas></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            // Never any image, but a different DOM state every time - exactly
            // what defeats the stagnation rule.
            $mock->shouldReceive('executeRecipe')->times(3)->andReturnUsing(function () use (&$round): array {
                $round++;

                return [
                    'images' => [],
                    'diagnostics' => ['distinct_dom_assets' => $round],
                    'action_trace' => [['action' => 'click', 'clicked' => true, 'changed' => true, 'round' => $round]],
                    'post_interaction_scout' => ['fragments' => ['<canvas data-round="'.$round.'"></canvas>']],
                ];
            });
        });

        $this->assertSame([], app(ProductGalleryRecipeTrainer::class)->train(
            'https://canvas-viewer.example/product',
            force: true,
        ));
        $version = ProductGalleryRecipe::query()
            ->where('domain', 'canvas-viewer.example')
            ->firstOrFail()
            ->versions()
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('page_stalled', $version->result['failure_kind']);
    }

    /** @return array<string, mixed> */
    private function validRecipeResponse(array $overrides = []): array
    {
        return array_replace([
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 3,
            'expected_count_evidence' => 'Three gallery thumbnails are visible.',
            'actions' => [[
                'kind' => 'click',
                'selector' => 'button[data-gallery]',
                'index' => 0,
                'limit' => 1,
                'wait_after_ms' => 200,
                'purpose' => 'Open the product media viewer.',
            ]],
            'pre_click_selectors' => [],
            'collect_selectors' => ['[data-gallery-image]'],
            'thumbnail_selectors' => [],
            'open_selectors' => ['button[data-gallery]'],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 3,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 150,
            'confidence' => 0.9,
            'reason' => 'Stable gallery controls found in page order.',
        ], $overrides);
    }

    public function test_repeated_identical_validation_failures_stop_training_before_the_round_cap(): void
    {
        AppSetting::put('ai.gallery_training_max_rounds', '10');
        $callCount = 0;
        ProductGalleryRecipeTrainerAgent::fake(function () use (&$callCount): array {
            $callCount++;

            // A different out-of-range value every round (never literally
            // repeated), but always the same hard constraint - proving the
            // breaker tracks the failing RULE, not the specific bad value.
            return $this->validRecipeResponse([
                'actions' => [[
                    'kind' => 'click',
                    'selector' => 'button[data-gallery]',
                    'index' => 40 + $callCount,
                    'limit' => 1,
                    'wait_after_ms' => 200,
                    'purpose' => 'Open the product media viewer.',
                ]],
            ]);
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://stuck.example/product',
            force: true,
        );

        $this->assertSame([], $images);
        $this->assertSame(3, $callCount, 'Training must abandon the URL after 3 identical validation failures instead of burning all 10 allowed rounds.');
        $recipe = ProductGalleryRecipe::query()->where('domain', 'stuck.example')->firstOrFail();
        $this->assertSame(1, $recipe->failure_count);
        $this->assertSame('recipe_mismatch', $recipe->last_failure_kind);
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('rejected', $version->status);
        $this->assertSame('recipe_mismatch', $version->result['failure_kind']);
        $this->assertStringContainsString('actions.*.index', $version->error);
    }

    public function test_a_different_validation_failure_in_between_resets_the_identical_failure_counter(): void
    {
        AppSetting::put('ai.gallery_training_max_rounds', '10');
        $callCount = 0;
        // Rounds 1-2: bad action index (same rule). Round 3: a materially
        // different rule (bad expected_image_count) interrupts the streak.
        // Rounds 4-6: bad action index again, three IN A ROW this time -
        // only then should the breaker fire, at round 6, not round 3.
        ProductGalleryRecipeTrainerAgent::fake(function () use (&$callCount): array {
            $callCount++;

            if ($callCount === 3) {
                return $this->validRecipeResponse(['expected_image_count' => 999]);
            }

            return $this->validRecipeResponse([
                'actions' => [[
                    'kind' => 'click',
                    'selector' => 'button[data-gallery]',
                    'index' => 40 + $callCount,
                    'limit' => 1,
                    'wait_after_ms' => 200,
                    'purpose' => 'Open the product media viewer.',
                ]],
            ]);
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://intermittent.example/product',
            force: true,
        );

        $this->assertSame([], $images);
        $this->assertSame(
            6,
            $callCount,
            'A differently-shaped failure at round 3 must reset the streak, so the breaker only fires after 3 fresh identical failures (rounds 4-6).',
        );
        $version = ProductGalleryRecipe::query()->where('domain', 'intermittent.example')
            ->firstOrFail()->versions()->latest('id')->firstOrFail();
        $this->assertSame('recipe_mismatch', $version->result['failure_kind']);
    }

    public function test_the_trainer_agent_can_call_a_read_only_tool_before_returning_its_recipe(): void
    {
        // Proves the HasTools wiring end-to-end: the framework's real
        // TextGenerationLoop (not faked) must see the ToolCall, resolve
        // GetRecipeHealth by name among the tools ProductGalleryRecipeTrainerAgent
        // actually supplies, execute its real handle() against this test's
        // real database, then feed the *second* fake response back as the
        // final structured recipe - a single train() round, transparent to
        // the outer PHP loop, which only ever sees the eventual prompt() result.
        ProductGalleryRecipe::query()->create([
            'domain' => 'tool-aware.example',
            'path_pattern' => '*',
            'status' => 'learning',
            'failure_count' => 1,
            'last_failure_kind' => 'recipe_mismatch',
        ]);
        ProductGalleryRecipeTrainerAgent::fake([
            new ToolCall(id: 'call_1', name: 'GetRecipeHealth', arguments: []),
            $this->validRecipeResponse(['actions' => [], 'open_selectors' => []]),
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
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
                'action_trace' => [],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://tool-aware.example/product',
            force: true,
        );

        $this->assertCount(3, $images);
        $this->assertSame(
            'active',
            ProductGalleryRecipe::query()->where('domain', 'tool-aware.example')->firstOrFail()->status,
        );
    }

    public function test_write_tools_are_only_offered_once_the_setting_is_enabled(): void
    {
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'write-tools.example',
            'path_pattern' => '*',
            'status' => 'learning',
        ]);
        $version = ProductGalleryRecipeVersion::query()->create([
            'product_gallery_recipe_id' => $recipe->id,
            'domain' => 'write-tools.example',
            'product_url' => 'https://write-tools.example/product',
            'trigger' => 'automatic',
            'status' => 'scouting',
            'provider' => 'openai',
            'model' => 'gpt-test',
        ]);
        $domainSettings = ProductSourceDomain::query()->create(['domain' => 'write-tools.example']);
        $makeAgent = fn (): ProductGalleryRecipeTrainerAgent => new ProductGalleryRecipeTrainerAgent(
            url: 'https://write-tools.example/product',
            domain: 'write-tools.example',
            version: $version,
            recipe: $recipe,
            domainSettings: $domainSettings,
            abandonSignal: new GalleryTrainingAbandonSignal,
        );

        $toolClasses = collect($makeAgent()->tools())->map(fn (object $tool): string => $tool::class)->all();
        $this->assertNotContains(AbandonGalleryTrainingAttempt::class, $toolClasses);
        $this->assertNotContains(FlagDomainRecipeNote::class, $toolClasses);

        AppSetting::put('ai.gallery_agent_write_tools_enabled', '1');

        $toolClasses = collect($makeAgent()->tools())->map(fn (object $tool): string => $tool::class)->all();
        $this->assertContains(AbandonGalleryTrainingAttempt::class, $toolClasses);
        $this->assertContains(FlagDomainRecipeNote::class, $toolClasses);
    }

    public function test_the_agent_can_abandon_a_url_it_judges_unrecoverable(): void
    {
        AppSetting::put('ai.gallery_agent_write_tools_enabled', '1');
        ProductGalleryRecipeTrainerAgent::fake([
            new ToolCall(id: 'call_1', name: 'AbandonGalleryTrainingAttempt', arguments: [
                'reason' => 'GetRecipeHealth shows 2 prior download-layer failures; this is the same pattern.',
                'failure_kind' => 'agent_abandoned',
            ]),
            $this->validRecipeResponse(['actions' => [], 'open_selectors' => []]),
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldNotReceive('executeRecipe');
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://agent-abandons.example/product',
            force: true,
        );

        $this->assertSame([], $images);
        $recipe = ProductGalleryRecipe::query()->where('domain', 'agent-abandons.example')->firstOrFail();
        $this->assertSame(1, $recipe->failure_count);
        $this->assertSame('agent_abandoned', $recipe->last_failure_kind);
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('agent_abandoned', $version->result['failure_kind']);
        $this->assertStringContainsString('GetRecipeHealth shows', $version->error);
        $this->assertDatabaseHas('ai_operations', [
            'tool' => 'AbandonGalleryTrainingAttempt',
            'action' => 'abandon_training_attempt',
            'status' => 'completed',
        ]);
    }

    public function test_the_agent_can_leave_a_domain_note_without_ending_the_session(): void
    {
        AppSetting::put('ai.gallery_agent_write_tools_enabled', '1');
        ProductGalleryRecipeTrainerAgent::fake([
            new ToolCall(id: 'call_1', name: 'FlagDomainRecipeNote', arguments: [
                'note' => 'The gallery tab navigates to a new page instead of expanding in place.',
                'category' => 'navigation_hazard',
            ]),
            $this->validRecipeResponse(['actions' => [], 'open_selectors' => []]),
        ])->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => [
                    'title' => 'Product page',
                    'fragments' => ['<div class=gallery></div>'],
                    'interactive_controls' => [],
                    'network_image_samples' => [],
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
                'action_trace' => [],
            ]);
        });

        $images = app(ProductGalleryRecipeTrainer::class)->train(
            'https://notes.example/product',
            force: true,
        );

        $this->assertCount(3, $images);
        $domainSettings = ProductSourceDomain::query()->where('domain', 'notes.example')->firstOrFail();
        $this->assertStringContainsString(
            'The gallery tab navigates to a new page instead of expanding in place.',
            $domainSettings->auto_agent_hint,
        );
    }
}
