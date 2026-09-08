<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Tools\AbandonGalleryTrainingAttempt;
use App\Ai\Tools\FlagDomainRecipeNote;
use App\Exceptions\InvalidGalleryRecipeException;
use App\Models\AppSetting;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\GalleryTrainingAbandonSignal;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

        // Structural-training fixtures use reserved .example image hosts.
        // Give those fixtures explicit downloaded pixels: a DNS failure must
        // no longer count as successful technical verification. Tests using
        // real HTTP fakes (the public IP fixtures) still exercise the loader.
        $resolver = app(ProductImageResolver::class);
        $dependencies = array_map(
            fn ($parameter) => app((string) $parameter->getType()),
            (new \ReflectionClass(ProductImageResolver::class))->getConstructor()->getParameters(),
        );
        $fixtureResolver = \Mockery::mock(ProductImageResolver::class, $dependencies)->makePartial();
        $fixtureBytes = $this->publishableJpeg();
        $fixtureResolver->shouldReceive('download')->andReturnUsing(
            function (string $url, int $maxBytes = 8388608, ?string &$failureReason = null, ?string $refererUrl = null) use ($resolver, $fixtureBytes): ?array {
                if (str_ends_with((string) parse_url($url, PHP_URL_HOST), '.example')) {
                    return ['bytes' => $fixtureBytes, 'source_url' => $url, 'mime_type' => 'image/jpeg',
                        'width' => 1200, 'height' => 800, 'confirmed_gallery' => false, 'partial_gallery' => false];
                }

                return $resolver->download($url, $maxBytes, $failureReason, $refererUrl);
            },
        );
        $this->app->instance(ProductImageResolver::class, $fixtureResolver);

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

    public function test_the_agent_is_told_what_budget_is_left_and_what_became_of_its_photos(): void
    {
        // Nothing in this system may decide when the agent has had enough
        // without telling it what "enough" means: it has the tool to end a
        // page, and it cannot weigh a thorough plan against a cheap one while
        // blind to the rounds, seconds and money left. The photo outcome is the
        // other half - training ends before the download checks run, so this is
        // the only way it ever learns that its last recipe here produced frames
        // that were all thrown away.
        $seenPrompt = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seenPrompt): array {
            $seenPrompt = json_decode($prompt, true);

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

        $this->assertArrayHasKey('remaining_budget', $seenPrompt);
        $this->assertArrayHasKey('rounds_left', $seenPrompt['remaining_budget']);
        $this->assertArrayHasKey('seconds_left', $seenPrompt['remaining_budget']);
        $this->assertArrayHasKey('money_spent_fraction', $seenPrompt['remaining_budget']);
        $this->assertArrayHasKey('previous_photo_outcome', $seenPrompt);
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
    public function test_the_browser_receives_the_conditional_and_follow_up_fields_the_agent_returned(): void
    {
        // The action plan is whitelisted field by field on its way out, so a
        // field the contract supports but the whitelist forgets is deleted
        // between validation and execution - silently, with the prompt still
        // asking for it and the validator still checking it. after_each_* was
        // in exactly that state: the agent was told to name the zoom control to
        // press after each thumbnail, and the browser never received one.
        $executed = null;
        ProductGalleryRecipeTrainerAgent::fake(function (): array {
            return array_merge($this->workingRecipe(), [
                'actions' => [
                    [
                        'kind' => 'click',
                        'when' => 'if_present',
                        'selector' => '#consent button.accept',
                        'index' => 0,
                        'limit' => 1,
                        'wait_after_ms' => 200,
                        'after_each_selector' => null,
                        'after_each_limit' => null,
                        'after_each_wait_after_ms' => null,
                        'purpose' => 'accept the consent wall when it is up',
                    ],
                    [
                        'kind' => 'click_each',
                        'when' => 'always',
                        'selector' => '.gallery .thumb',
                        'index' => 0,
                        'limit' => 3,
                        'wait_after_ms' => 200,
                        'after_each_selector' => '.viewer .zoom',
                        'after_each_limit' => 2,
                        'after_each_wait_after_ms' => 300,
                        'purpose' => 'walk the thumbnails and enlarge each frame',
                    ],
                ],
            ]);
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$executed): void {
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
            ]);
            // Not restricted to one call: an incomplete traversal sends the
            // round back, and this test is about what crosses the boundary,
            // not about how many rounds the plan takes to converge.
            $mock->shouldReceive('executeRecipe')
                ->andReturnUsing(function (string $url, array $recipe) use (&$executed): array {
                    $executed ??= $recipe;

                    return [
                        'images' => [
                            'https://storage.example/one.webp',
                            'https://storage.example/two.webp',
                            'https://storage.example/three.webp',
                        ],
                        'action_trace' => [
                            ['action' => 'click', 'action_index' => 0, 'clicked' => false, 'optional_absent' => true, 'selector_match_count' => 0],
                            ['action' => 'click_each', 'action_index' => 1, 'clicked' => true, 'changed' => true, 'selector_match_count' => 3],
                        ],
                    ];
                });
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertNotNull($executed);
        $this->assertSame('if_present', $executed['actions'][0]['when']);
        $this->assertSame('always', $executed['actions'][1]['when']);
        $this->assertSame('.viewer .zoom', $executed['actions'][1]['after_each_selector']);
        $this->assertSame(2, $executed['actions'][1]['after_each_limit']);
        $this->assertSame(300, $executed['actions'][1]['after_each_wait_after_ms']);
        $this->assertNull($executed['actions'][0]['after_each_selector']);
    }

    public function test_a_rate_limit_does_not_take_the_screenshot_away(): void
    {
        // The screenshot is dropped so a request the provider refused to read
        // can get through on the retry. A 429 was read as one, and one busy
        // moment cost the agent its view of the page for the rest of the
        // training over a fault that had nothing to do with the attachment.
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $method = new \ReflectionMethod($trainer, 'looksLikeRejectedRequest');

        $this->assertFalse($method->invoke($trainer, new \RuntimeException('HTTP 429 Too Many Requests')));
        $this->assertFalse($method->invoke($trainer, new \RuntimeException('Request timed out after 90s')));
        $this->assertFalse($method->invoke($trainer, new \RuntimeException('connection reset by peer')));
        $this->assertTrue($method->invoke($trainer, new \RuntimeException('HTTP 400 invalid_request_error: image url is malformed')));
    }

    public function test_two_different_provider_errors_are_not_the_same_failure(): void
    {
        // Erasing every digit made a 400 and a 429 share one signature, so three
        // unrelated failures tripped a breaker built for one failure repeating.
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $method = new \ReflectionMethod($trainer, 'technicalFailureSignature');

        $this->assertNotSame(
            $method->invoke($trainer, new \RuntimeException('HTTP 400 bad request')),
            $method->invoke($trainer, new \RuntimeException('HTTP 429 bad request')),
        );
        // Ids and timestamps still must not make one fault look like two.
        $this->assertSame(
            $method->invoke($trainer, new \RuntimeException('run 173829911 failed: HTTP 400')),
            $method->invoke($trainer, new \RuntimeException('run 173829977 failed: HTTP 400')),
        );
    }

    public function test_a_recipe_that_only_yields_thumbnails_is_not_promoted_and_the_agent_is_told_the_sizes(): void
    {
        // Live on lenovo.com: the gallery was fully traversable and every URL
        // it exposed was 584px wide against a 700px floor. Training ended at
        // "the browser returned these URLs" and the pixels were measured much
        // later, by a different part of the search, long after the agent had
        // stopped listening - so a recipe could be promoted for collecting a
        // gallery of thumbnails that never put a photograph in the catalog.
        $seen = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seen): array {
            $seen[] = json_decode($prompt, true);

            return $this->workingRecipe();
        })->preventStrayPrompts();
        Http::fake([
            '93.184.216.34/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            // The downloader asks the extractor whether a URL came from a
            // confirmed gallery; without this the probe fails technically and
            // proves nothing either way.
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            $mock->shouldReceive('executeRecipe')->andReturn([
                'images' => [
                    'https://93.184.216.34/one.jpg',
                    'https://93.184.216.34/two.jpg',
                    'https://93.184.216.34/three.jpg',
                ],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $recipe = ProductGalleryRecipe::query()->where('domain', 'us.msi.com')->first();
        $this->assertNotSame(
            'active',
            $recipe?->status,
            'A gallery of thumbnails is not a working recipe, however complete its traversal.',
        );

        $feedback = collect($seen)->pluck('previous_attempt_feedback')->filter()->values();
        $this->assertNotEmpty($feedback, 'The next round must be told what the downloader made of these frames.');
        $measured = $feedback->pluck('downloaded_frames')->filter()->first();
        $this->assertNotNull($measured);
        $this->assertSame(0, $measured['usable']);
        $this->assertGreaterThan(0, $measured['fetched']);
        $this->assertStringContainsString('full-size source', $measured['instruction']);
    }

    public function test_frames_that_could_not_be_fetched_at_all_are_not_read_as_a_verdict(): void
    {
        // The other half of the rule: DNS, a timeout or a 403 says nothing
        // about what the recipe collects, and must not become "this gallery is
        // unpublishable".
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->workingRecipe())->preventStrayPrompts();
        Http::fake(['unreachable.example/*' => Http::response('', 403)]);
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn([
                'images' => [
                    'https://unreachable.example/one.jpg',
                    'https://unreachable.example/two.jpg',
                    'https://unreachable.example/three.jpg',
                ],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertSame(
            'active',
            ProductGalleryRecipe::query()->where('domain', 'us.msi.com')->first()?->status,
        );
    }

    public function test_one_publishable_frame_among_thumbnails_is_still_a_thumbnail_recipe(): void
    {
        // Live on cdw.com: the recipe collected nine frames, the probe found one
        // of four publishable, and that passed the bar - which was "not a single
        // usable frame". So the recipe was promoted, the search downloaded all
        // nine, one photograph reached the catalog, and the agent had stopped
        // listening long before any of that was known.
        //
        // A recipe yielding one publishable frame in four is collecting
        // thumbnails as surely as one yielding none; it just has a stray
        // full-size frame among them.
        $seen = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$seen): array {
            $seen[] = json_decode($prompt, true);

            return $this->workingRecipe();
        })->preventStrayPrompts();
        Http::fake([
            '93.184.216.34/full.jpg' => Http::response($this->publishableJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '93.184.216.34/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'CDW HP OmniBook 7',
                    'fragments' => [],
                    'interactive_controls' => ['<button aria-label="Expand">+ 3 Photos</button>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            $mock->shouldReceive('executeRecipe')->andReturn([
                'images' => [
                    'https://93.184.216.34/full.jpg',
                    'https://93.184.216.34/one.jpg',
                    'https://93.184.216.34/two.jpg',
                    'https://93.184.216.34/three.jpg',
                    'https://93.184.216.34/four.jpg',
                    'https://93.184.216.34/five.jpg',
                ],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://www.cdw.com/product/hp-omnibook-7-16-ay0087nr/9013934',
            force: true,
            context: ['minimum_verified_images' => 5],
        );

        $recipe = ProductGalleryRecipe::query()->where('domain', 'www.cdw.com')->first();
        $this->assertNotSame(
            'active',
            $recipe?->status,
            'One full-size frame among five thumbnails is not a gallery worth promoting.',
        );

        $feedback = collect($seen)->pluck('previous_attempt_feedback')->filter()->values();
        $measured = $feedback->pluck('downloaded_frames')->filter()->first();

        $this->assertNotNull($measured, 'The next round must be told what the downloader made of these frames.');
        $this->assertSame(1, $measured['usable'], 'The one real photograph was measured as such.');
        $this->assertGreaterThan(1, $measured['fetched']);
        $this->assertStringContainsString('full-size source', $measured['instruction']);
    }

    public function test_the_arithmetic_is_named_rather_than_asserted(): void
    {
        // The agent has to be able to argue with the verdict, which means seeing
        // how it was reached: the rate measured, the frames collected, the
        // number this search needs.
        $errors = [];
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->workingRecipe())->preventStrayPrompts();
        Http::fake([
            '93.184.216.34/full.jpg' => Http::response($this->publishableJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '93.184.216.34/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'CDW HP OmniBook 7',
                    'fragments' => [],
                    'interactive_controls' => ['<button aria-label="Expand">+ 3 Photos</button>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            // Six frames against a minimum of five: the structural check passes,
            // which is the only way the download probe runs at all.
            $mock->shouldReceive('executeRecipe')->andReturn([
                'images' => [
                    'https://93.184.216.34/full.jpg',
                    'https://93.184.216.34/one.jpg',
                    'https://93.184.216.34/two.jpg',
                    'https://93.184.216.34/three.jpg',
                    'https://93.184.216.34/four.jpg',
                    'https://93.184.216.34/five.jpg',
                ],
            ]);
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://www.cdw.com/product/hp-omnibook-7-16-ay0087nr/9013934',
            force: true,
            context: ['minimum_verified_images' => 5],
        );

        $version = ProductGalleryRecipeVersion::query()->where('domain', 'www.cdw.com')->latest('id')->first();
        $attempts = collect($version?->result['attempts'] ?? []);
        $reason = (string) $attempts->pluck('validation.reason')->filter()->first();

        $this->assertStringContainsString('can be published', $reason);
        $this->assertStringContainsString('this search needs 5', $reason);
    }

    private function publishableJpeg(): string
    {
        // 1200x800 - comfortably over any category floor, so the probe counts it.
        $image = imagecreatetruecolor(1200, 800);
        imagefilledrectangle($image, 0, 0, 1199, 799, imagecolorallocate($image, 40, 90, 140));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_unavailable_downloads_interrupt_training_without_activating_or_penalising_the_recipe(): void
    {
        AppSetting::put('ai.gallery_training_max_rounds', '1');
        Http::fake(fn () => Http::response('', 403));
        ProductGalleryRecipeTrainerAgent::fake(fn () => $this->workingRecipe())->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => ['title' => 'Laptop', 'fragments' => [], 'interactive_controls' => ['Gallery']],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturn(['images' => [
                'https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg', 'https://93.184.216.34/c.jpg',
            ]]);
        });
        $result = app(ProductGalleryRecipeTrainer::class)->train('https://unavailable.example/product', force: true);
        $recipe = ProductGalleryRecipe::where('domain', 'unavailable.example')->firstOrFail();
        $version = $recipe->versions()->firstOrFail();
        $this->assertSame([
            'https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg', 'https://93.184.216.34/c.jpg',
        ], $result, 'Unverified URLs must survive for downstream checks, not disappear with the failed activation.');
        $this->assertNotSame('active', $recipe->status);
        $this->assertSame(0, $recipe->failure_count);
        $this->assertSame('interrupted', $version->status);
        $this->assertNull($version->promoted_at);
        $this->assertSame('download_interrupted', $version->result['failure_kind']);
        $this->assertCount(1, $version->result['attempts']);
    }

    public function test_global_budget_deferral_returns_observed_frames_without_promoting_the_recipe(): void
    {
        $update = TelegramUpdate::create([
            'update_id' => 991122, 'telegram_user_id' => '111', 'chat_id' => '222',
            'message_id' => 1, 'payload' => [], 'status' => 'processing',
        ]);
        $executed = false;
        $this->mock(ProductSearchCostBudget::class, function (MockInterface $mock) use (&$executed): void {
            $mock->shouldReceive('limit')->andReturn(1.0);
            $mock->shouldReceive('unmeasurable', 'reachedFraction')->andReturn(false);
            $mock->shouldReceive('exceeded')->andReturnUsing(function () use (&$executed) { return $executed; });
            $mock->shouldReceive('spent', 'spentFraction')->andReturn(0.0);
            $mock->shouldNotReceive('exceededForSource');
        });
        ProductGalleryRecipeTrainerAgent::fake(fn () => [...$this->workingRecipe(),
            'actions' => [['kind' => 'click_until_no_change', 'selector' => '.next', 'index' => 0,
                'limit' => 14, 'wait_after_ms' => 100, 'purpose' => 'Traverse the product viewer']],
        ])->preventStrayPrompts();
        $urls = array_map(fn ($n) => 'https://cdn.example/frame-'.$n.'.jpg', range(1, 15));
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use ($urls, &$executed): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => ['title' => 'Laptop', 'fragments' => [], 'interactive_controls' => ['Media']],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->once()->andReturnUsing(function () use ($urls, &$executed) {
                $executed = true;
                return [
                'images' => $urls, 'diagnostics' => ['action_plan' => ['required' => true, 'complete' => false]],
                ];
            });
        });
        // Use the measurable-budget branch with the test database and AI fakes.
        $this->app->instance('env', 'local');
        try {
            $result = app(ProductGalleryRecipeTrainer::class)->train(
                'https://budget.example/product', force: true, telegramUpdateId: $update->id,
            );
        } finally {
            $this->app->instance('env', 'testing');
        }
        $version = ProductGalleryRecipeVersion::latest('id')->firstOrFail();
        $this->assertSame($urls, $result);
        $this->assertSame('deferred', $version->status);
        $this->assertNull($version->promoted_at);
        $this->assertSame(15, $version->result['best_partial_count']);
        $this->assertNotSame('active', ProductGalleryRecipe::where('domain', 'budget.example')->firstOrFail()->status);
    }

    public function test_temporary_download_failure_can_recover_in_the_same_session(): void
    {
        $prompts = [];
        $bytes = $this->publishableJpeg();
        Http::fake(function () use (&$prompts, $bytes) {
            return count($prompts) <= 1 ? Http::response('', 403) : Http::response($bytes, 200);
        });
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts) {
            $prompts[] = json_decode($prompt, true);

            return $this->workingRecipe();
        })->preventStrayPrompts();
        $urls = ['https://93.184.216.34/a.jpg', 'https://93.184.216.34/b.jpg', 'https://93.184.216.34/c.jpg'];
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use ($urls): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => ['title' => 'Laptop', 'fragments' => [], 'interactive_controls' => ['Gallery']],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->twice()->andReturn(['images' => $urls]);
        });
        $result = app(ProductGalleryRecipeTrainer::class)->train('https://retry.example/product', force: true);
        $this->assertSame($urls, $result);
        $this->assertCount(2, $prompts);
        $this->assertSame(3, $prompts[1]['previous_attempt_feedback']['downloaded_frames']['unknown']);
        $this->assertStringContainsString('interrupted', $prompts[1]['previous_attempt_feedback']['error']);
        $recipe = ProductGalleryRecipe::where('domain', 'retry.example')->firstOrFail();
        $this->assertSame('active', $recipe->status);
        $this->assertSame(1, $recipe->versions()->count());
    }

    public function test_failures_after_the_first_four_frames_are_repaired_in_the_same_training_session(): void
    {
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return $this->workingRecipe();
        })->preventStrayPrompts();
        $good = $this->publishableJpeg();
        $small = $this->tinyJpeg();
        Http::fake(fn ($request) => Http::response(
            str_contains($request->url(), '/small-') ? $small : $good,
            200, ['Content-Type' => 'image/jpeg'],
        ));
        $first = array_map(fn ($n) => 'https://93.184.216.34/'.($n < 5 ? 'good-' : 'small-').$n.'.jpg', range(1, 7));
        $fixed = array_map(fn ($n) => 'https://93.184.216.34/full-'.$n.'.jpg', range(1, 7));
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use ($first, $fixed): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => ['title' => 'Laptop', 'fragments' => [], 'interactive_controls' => ['Gallery']],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            $mock->shouldReceive('executeRecipe')->twice()->andReturn(['images' => $first], ['images' => $fixed]);
        });

        $result = app(ProductGalleryRecipeTrainer::class)->train(
            'https://repair.example/product', force: true,
            context: ['minimum_verified_images' => 7],
        );

        $this->assertCount(2, $prompts);
        $feedback = $prompts[1]['previous_attempt_feedback']['downloaded_frames'];
        $this->assertSame(7, $feedback['fetched']);
        $this->assertSame(4, $feedback['usable']);
        $this->assertSame('too_small', $feedback['frames'][4]['status']);
        $this->assertSame($first[4], $feedback['frames'][4]['url']);
        $this->assertSame($fixed, $result);
        $recipe = ProductGalleryRecipe::where('domain', 'repair.example')->firstOrFail();
        $this->assertSame('active', $recipe->status);
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertCount(2, $recipe->versions()->first()->result['attempts']);
    }

    public function test_trained_recipe_is_reused_for_another_product_without_another_llm_call(): void
    {
        config(['product-images.browser_fallback.enabled' => true]);
        $prompts = 0;
        ProductGalleryRecipeTrainerAgent::fake(function () use (&$prompts): array {
            $prompts++;

            return $this->workingRecipe();
        })->preventStrayPrompts();
        $firstPage = 'https://transfer.example/product-a';
        $secondPage = 'https://transfer.example/product-b';
        $firstImages = array_map(fn ($n) => 'https://cdn.example/a-'.$n.'.jpg', range(1, 3));
        $secondImages = array_map(fn ($n) => 'https://cdn.example/b-'.$n.'.jpg', range(1, 7));
        $dependencies = array_map(
            fn ($parameter) => app((string) $parameter->getType()),
            (new \ReflectionClass(BrowserProductGalleryExtractor::class))->getConstructor()->getParameters(),
        );
        // Only the browser process is replaced. Routing, learning, persistence
        // and result validation execute normally for both product pages.
        $browser = \Mockery::mock(BrowserProductGalleryExtractor::class, $dependencies)->makePartial();
        $browser->shouldReceive('scout')->once()->andReturn([
            'scout' => ['title' => 'Product A', 'fragments' => [], 'interactive_controls' => ['Gallery']],
            'diagnostics' => [],
        ]);
        $executions = [];
        $browser->shouldReceive('executeRecipe')->twice()->andReturnUsing(
            function (string $url, array $recipe) use (&$executions, $firstPage, $secondPage, $firstImages, $secondImages): array {
                $executions[] = ['url' => $url, 'recipe' => $recipe];
                $this->assertContains($url, [$firstPage, $secondPage]);

                return ['images' => $url === $firstPage ? $firstImages : $secondImages];
            },
        );
        $this->app->instance(BrowserProductGalleryExtractor::class, $browser);

        $this->assertSame($firstImages, app(ProductGalleryRecipeTrainer::class)->train($firstPage, force: true));
        $stored = ProductGalleryRecipe::where('domain', 'transfer.example')->firstOrFail();
        $this->assertSame('active', $stored->status);
        $this->assertArrayNotHasKey('expected_image_count', $stored->recipe);
        $this->assertSame($secondImages, $browser->extract($secondPage));
        $this->assertSame(1, $prompts, 'Product B must not retrain a working recipe.');
        $this->assertSame($secondPage, $executions[1]['url']);
        $this->assertSame($stored->recipe, $executions[1]['recipe']);
        $this->assertSame(1, ProductGalleryRecipeVersion::count());
        $this->assertSame(1, ProductGalleryRecipe::count());
        $this->assertSame(7, count($secondImages), 'The three photos on A must not cap B.');
    }

    public function test_download_measurements_do_not_extrapolate_or_count_network_failures_as_small_images(): void
    {
        Http::fake([
            '93.184.216.34/small-*' => Http::response($this->tinyJpeg(), 200),
            '93.184.216.34/unavailable' => Http::response('', 403),
            '*' => Http::response($this->publishableJpeg(), 200),
        ]);
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(false);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
        });
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $method = new \ReflectionMethod($trainer, 'measureDownloadableFrames');
        $urls = array_map(fn ($n) => 'https://93.184.216.34/'.($n < 4 ? 'small-' : 'full-').$n, range(1, 8));
        $urls[] = 'https://93.184.216.34/unavailable';
        $result = $method->invoke($trainer, $urls, [], 'https://repair.example/product');

        $this->assertSame(9, $result['measured']);
        $this->assertSame(8, $result['fetched']);
        $this->assertSame(5, $result['usable']);
        $this->assertSame(1, $result['unknown']);
        $this->assertFalse($result['complete']);
        $this->assertSame('unavailable', $result['frames'][8]['status']);
    }

    public function test_download_measurements_leave_unvisited_frames_unknown_when_time_is_reserved(): void
    {
        Http::fake();
        $this->mock(ProductSearchTimeBudget::class, function (MockInterface $mock): void {
            $mock->shouldReceive('timeoutFor')->once()->andReturn(1);
            $mock->shouldReceive('canStart')->once()->with(123, 1)->andReturn(false);
        });
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $method = new \ReflectionMethod($trainer, 'measureDownloadableFrames');
        $result = $method->invoke($trainer, ['https://93.184.216.34/photo.jpg'], [], 'https://repair.example/product', 123);

        $this->assertSame(0, $result['measured']);
        $this->assertSame(1, $result['unknown']);
        $this->assertFalse($result['complete']);
        Http::assertNothingSent();
    }

    private function tinyJpeg(): string
    {
        // 120x90 - a real photograph, and far under any category floor.
        $image = imagecreatetruecolor(120, 90);
        imagefilledrectangle($image, 0, 0, 119, 89, imagecolorallocate($image, 90, 90, 90));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function test_a_preflight_that_broke_technically_is_asked_again(): void
    {
        // Live on cdw.com: the stored recipe had already collected all six
        // photographs when the classification call broke. Everything
        // downstream reads an interrupted classification as "the gallery was
        // not confirmed", so the source was sent off to be scraped blind and
        // came back with one tracking pixel's worth of nothing.
        //
        // A call that never reached the agent is not the agent's answer.
        $calls = 0;
        ProductGalleryPreflightAgent::fake(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new RuntimeException('provider stream closed');
            }

            return [
                'decision' => 'train_playwright',
                'gallery_likely' => true,
                'hidden_images_likely' => true,
                'interaction_required' => true,
                'expected_image_count' => 2,
                'evidence' => ['gallery fixture'],
                'confidence' => 0.95,
                'reason' => 'Fixture requires browser interaction.',
            ];
        })->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->workingRecipe())->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            $mock->shouldReceive('executeRecipe')->andReturn([
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

        $this->assertSame(2, $calls, 'The interrupted classification must be asked again before the page is written off.');
        $this->assertSame(
            'active',
            ProductGalleryRecipe::query()->where('domain', 'us.msi.com')->first()?->status,
            'With the second answer the page trains normally instead of being scraped blind.',
        );
    }

    public function test_a_recipe_that_stopped_working_is_handed_back_for_repair_not_rediscovery(): void
    {
        // A stored recipe that fails on a new page is not a blank page. It
        // opened this site correctly before, and what changed is knowable -
        // which selector matched nothing, which step stopped early. Starting
        // the agent from nothing paid for a full rediscovery of a site we
        // already knew and produced a second recipe beside the first rather
        // than a better one.
        $firstPrompt = null;
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$firstPrompt): array {
            $firstPrompt ??= json_decode($prompt, true);

            return $this->workingRecipe();
        })->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            $mock->shouldReceive('executeRecipe')->andReturn([
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
            repairFrom: [
                'recipe' => ['collect_selectors' => ['.old-gallery img'], 'gallery_present' => true],
                'reason' => 'Gallery traversal incomplete: clicked 0 of 4 required thumbnail controls.',
                'action_trace' => [['action' => 'click_each', 'clicked' => false, 'selector_missing' => true]],
                'diagnostics' => ['observed_gallery_count' => 4],
                'images' => [],
            ],
        );

        $feedback = $firstPrompt['previous_attempt_feedback'] ?? null;

        $this->assertNotNull($feedback, 'Round one must open with the broken recipe, not with a blank page.');
        $this->assertSame(['.old-gallery img'], $feedback['rejected_recipe']['collect_selectors']);
        $this->assertStringContainsString('clicked 0 of 4', $feedback['error']);
        $this->assertStringContainsString('Repair the step that failed here', $feedback['instruction']);
    }

    public function test_a_repair_that_breaks_a_page_the_recipe_already_opened_is_not_promoted(): void
    {
        // The fix shaped around the page in front of the agent, which quietly
        // breaks every other page of the site. Without this the breakage
        // surfaces days later as a domain that stopped producing photographs.
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'us.msi.com',
            // The shop's own recipe: training writes here, not to a row
            // scoped to the one product it happened to learn on.
            'path_pattern' => '*',
            'status' => 'active',
            'recipe' => ['collect_selectors' => ['.gallery img'], 'gallery_present' => true, 'content_confirmed_product' => true],
            'success_count' => 4,
            'source_blocked' => false,
        ]);
        ProductSourceAttempt::query()->create([
            'domain' => 'us.msi.com',
            'product_url' => 'https://us.msi.com/Laptop/Another-Model/Specification',
            'actor' => 'playwright',
            'phase' => 'active_recipe',
            'action' => 'extract_gallery',
            'status' => 'completed',
            'decision' => 'gallery_extracted',
        ]);
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->workingRecipe())->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('isConfirmedGalleryImage')->andReturn(true);
            $mock->shouldReceive('isPartialGalleryImage')->andReturn(false);
            // The page being trained is happy with the new recipe; the page it
            // used to open gets nothing out of it.
            $mock->shouldReceive('executeRecipe')->andReturnUsing(
                fn (string $url): array => str_contains($url, 'Another-Model')
                    ? ['images' => []]
                    : ['images' => [
                        'https://storage.example/one.webp',
                        'https://storage.example/two.webp',
                        'https://storage.example/three.webp',
                    ]],
            );
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertSame(
            ['.gallery img'],
            $recipe->fresh()->recipe['collect_selectors'],
            'The version that still opens the rest of the site stays.',
        );
        // The shop keeps the recipe that still opens the rest of it, and this
        // page family gets its own rather than being left unopenable - the one
        // way a narrower scope is created, and only from evidence.
        $narrower = ProductGalleryRecipe::query()
            ->where('domain', 'us.msi.com')
            ->where('path_pattern', '!=', '*')
            ->first();

        $this->assertNotNull($narrower, 'The family that needs a different recipe must get one.');
        $this->assertSame('active', $narrower->status);
        $this->assertSame('/Laptop/Katana-17-HX-B14WX/*', $narrower->path_pattern);
        $this->assertArrayNotHasKey(
            'expected_image_count',
            $narrower->recipe,
            'A forked recipe carries no count either.',
        );
        $this->assertSame('promoted', $narrower->versions()->latest('id')->first()?->status);
        $this->assertSame(0, $recipe->fresh()->versions()->count());
    }

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

    public function test_gallery_observation_counts_are_not_execution_limits(): void
    {
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $preflight = new \ReflectionMethod($trainer, 'preflight');
        $validate = new \ReflectionMethod($trainer, 'validateRecipe');

        foreach ([0, 21, 999, -1, 1.5] as $count) {
            ProductGalleryPreflightAgent::fake(fn (): array => [
                'decision' => 'train_playwright',
                'page_kind' => 'product_card',
                'gallery_likely' => true,
                'hidden_images_likely' => true,
                'interaction_required' => true,
                'expected_image_count' => $count,
                'evidence' => ['Current gallery observation.'],
                'confidence' => 0.95,
                'reason' => 'Gallery needs interaction.',
            ])->preventStrayPrompts();

            $result = $preflight->invoke(
                $trainer, 'https://gallery.example/product', [], [], [], null, null,
                'openai', 'gpt-5-mini', new ProductGalleryRecipeVersion, null, null,
            );
            if ($count < 0 || ! is_int($count)) {
                $this->assertSame('interrupted', $result['decision']);

                try {
                    $validate->invoke($trainer, [
                        ...$this->workingRecipe(), 'expected_image_count' => $count,
                    ]);
                    $this->fail('An invalid observation must not become a valid recipe.');
                } catch (InvalidGalleryRecipeException $exception) {
                    $this->assertContains('expected_image_count', $exception->ruleSignature);
                }

                continue;
            }
            $this->assertSame('train_playwright', $result['decision'], $result['reason']);
            $this->assertSame($count, $result['expected_image_count']);

            $recipe = $validate->invoke($trainer, [
                ...$this->workingRecipe(), 'expected_image_count' => $count,
            ]);
            $this->assertSame($count, $recipe['expected_image_count']);
        }
    }

    public function test_gallery_count_schemas_do_not_cap_current_page_observations(): void
    {
        foreach ([new ProductGalleryPreflightAgent, new ProductGalleryRecipeTrainerAgent] as $agent) {
            $schema = $agent->schema(new JsonSchemaTypeFactory);
            $count = $schema['expected_image_count']->toArray();
            $this->assertSame(0, $count['minimum']);
            $this->assertArrayNotHasKey('maximum', $count);
        }
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

    public function test_unexplored_gallery_is_reconsidered_and_can_be_trained_without_losing_the_starting_page(): void
    {
        $this->assertGalleryAbandonmentReview(recovers: true);
    }

    public function test_repeated_unexplored_abandonment_is_interrupted_not_a_persistent_page_ban(): void
    {
        $this->assertGalleryAbandonmentReview(recovers: false);
    }

    private function assertGalleryAbandonmentReview(bool $recovers): void
    {
        AppSetting::put('ai.gallery_training_max_rounds', '5');
        $page = 'https://prospect.example/products/laptop';
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts, $recovers): array {
            $prompts[] = json_decode($prompt, true);
            if ($recovers && count($prompts) > 1) {
                return $this->workingRecipe();
            }

            return [...$this->workingRecipe(),
                'training_decision' => 'abandon_page',
                'page_kind' => 'product_family_landing',
                'page_assessment_evidence' => [
                    'This page contains feature illustrations.',
                    'A separate same-product media control is present; the gallery is on that linked page.',
                ],
                'content_confirmed_product' => false,
                'expected_image_count' => 0,
                'reason' => 'Train the linked media page instead of the starting page.',
            ];
        })->preventStrayPrompts();
        $urls = ['https://cdn.example/one.jpg', 'https://cdn.example/two.jpg', 'https://cdn.example/three.jpg'];
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use ($recovers, $page, $urls): void {
            $mock->shouldReceive('scout')->once()->andReturn([
                'scout' => ['title' => 'Laptop', 'fragments' => [],
                    'interactive_controls' => ['Product media control']],
                'diagnostics' => [],
            ]);
            if ($recovers) {
                $mock->shouldReceive('executeRecipe')->once()->withArgs(
                    fn ($url, $recipe) => $url === $page && $recipe['pre_click_selectors'] !== [],
                )->andReturn(['images' => $urls]);
            } else {
                $mock->shouldNotReceive('executeRecipe');
            }
        });
        $result = app(ProductGalleryRecipeTrainer::class)->train($page, force: true);
        $this->assertCount(2, $prompts);
        $this->assertSame('abandon_page', $prompts[1]['previous_attempt_feedback']['rejected_recipe']['training_decision']);
        $this->assertStringContainsString('unexplored gallery prospect', $prompts[1]['previous_attempt_feedback']['error']);
        $this->assertSame($recovers ? $urls : [], $result);
        $this->assertDatabaseMissing('product_source_page_rules', ['domain' => 'prospect.example']);
        $version = ProductGalleryRecipeVersion::latest('id')->firstOrFail();
        $this->assertSame($recovers ? 'promoted' : 'interrupted', $version->status);
        $this->assertSame(0, (int) ProductGalleryRecipe::where('domain', 'prospect.example')->firstOrFail()->failure_count);
        if (! $recovers) {
            $this->assertNull($version->promoted_at);
            $this->assertSame('unexplored_gallery_prospect', $version->result['failure_kind']);
            $resume = new \ReflectionMethod(ProductGalleryRecipeTrainer::class, 'interruptedTrainingProgress');
            $progress = $resume->invoke(app(ProductGalleryRecipeTrainer::class),
                ProductGalleryRecipe::where('domain', 'prospect.example')->firstOrFail());
            $this->assertStringContainsString('Gallery prospect remains unverified', $progress['feedback']['error']);
            $this->assertSame('abandon_page', $progress['feedback']['rejected_recipe']['training_decision']);
        }
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

    public function test_empty_collections_with_new_dom_can_reach_the_gallery_in_a_later_round(): void
    {
        // Live case 2026-09-03: acer.com renders its gallery into a Scene7
        // canvas, so no selector can ever reach an image. Every round clicked
        // successfully and moved the DOM differently, which kept the stagnation
        // signature changing and let the session burn seven paid rounds on a
        // page that cannot be scraped by selector at all.
        AppSetting::put('ai.gallery_training_max_rounds', '10');
        $round = 0;
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->validRecipeResponse(['actions' => []]))->preventStrayPrompts();
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
            $mock->shouldReceive('executeRecipe')->times(4)->andReturnUsing(function () use (&$round): array {
                $round++;

                return [
                    'images' => $round === 4 ? ['https://cdn.example/a.jpg', 'https://cdn.example/b.jpg', 'https://cdn.example/c.jpg'] : [],
                    'diagnostics' => ['distinct_dom_assets' => $round],
                    'action_trace' => [['action' => 'click', 'clicked' => true, 'changed' => true, 'round' => $round]],
                    'post_interaction_scout' => ['fragments' => ['<canvas data-round="'.$round.'"></canvas>']],
                ];
            });
        });

        $this->assertCount(3, app(ProductGalleryRecipeTrainer::class)->train(
            'https://canvas-viewer.example/product',
            force: true,
        ));
        // Two different vocabularies, and the recipe's is the one that says
        // the page can be opened again tomorrow: a version is promoted,
        // partial or rejected, while the recipe it belongs to is active.
        $recipe = ProductGalleryRecipe::query()->where('domain', 'canvas-viewer.example')->firstOrFail();

        $this->assertSame('promoted', $recipe->versions()->latest('id')->firstOrFail()->status);
        $this->assertSame('active', $recipe->status);
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
                return $this->validRecipeResponse(['expected_image_count' => -1]);
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
