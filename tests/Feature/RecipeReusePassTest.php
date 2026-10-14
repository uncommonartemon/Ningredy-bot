<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Playwright\BrowserRunner;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use App\Services\Products\ProductGalleryRecipeResultValidator;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeReusePassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['product-images.browser_fallback.enabled' => true]);
    }

    public function test_undersized_frames_reach_the_agent_without_penalizing_or_repeating_the_exact_recipe(): void
    {
        $this->assertQualityDiagnosis('/products/*');
    }

    public function test_undersized_frames_on_a_new_path_keep_the_prior_recipe_and_diagnostics(): void
    {
        $this->assertQualityDiagnosis('/notebooks/*');
    }

    private function assertQualityDiagnosis(string $path): void
    {
        $recipe = $this->recipe($path);
        $result = $this->qualityResult(2, 2);
        $browser = $this->browser($result);
        $url = 'https://reuse.example/products/quality-check';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()
            ->andReturnUsing(function (...$arguments) use ($recipe, $result): array {
                $feedback = $arguments['repairFrom'] ?? $arguments[9];
                $this->assertSame('source_diagnosis', $feedback['mode']);
                $this->assertSame('source_quality_unconfirmed', $feedback['failure_kind']);
                $this->assertSame($recipe->recipe, $feedback['recipe']);
                $this->assertSame($result['diagnostics'], $feedback['diagnostics']);
                $this->assertSame($result['images'], $feedback['images']);

                return $result['images'];
            });
        $this->assertSame($result['images'], $browser->extract($url, activeRecipeOnly: true));
        $this->assertFalse($browser->isConfirmedGalleryImage($result['images'][0]));
        $browser->extract($url);
        $this->assertCount(1, $browser->executions, 'The cheap reuse pass already measured the frames.');
        $this->assertSame(4, $recipe->fresh()->success_count);
        $this->assertSame(0, $recipe->fresh()->failure_count);
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertNull($recipe->fresh()->compatible_path_patterns);
    }

    public function test_one_small_frame_does_not_send_an_otherwise_sufficient_gallery_to_retraining(): void
    {
        $recipe = $this->recipe('/products/*');
        $result = $this->qualityResult(6, 1);
        $browser = $this->browser($result);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $this->assertSame($result['images'], $browser->extract('https://reuse.example/products/adequate'));
        $this->assertTrue($browser->isConfirmedGalleryImage($result['images'][0]));
        $this->assertSame(5, $recipe->fresh()->success_count);
        $this->assertSame(0, $recipe->fresh()->failure_count);
    }

    private function qualityResult(int $large, int $small): array
    {
        return ['images' => array_map(fn ($i) => 'https://cdn.example/large-'.$i.'.jpg', range(1, $large)),
            'learned_recipe' => ['collect_selectors' => ['.gallery img']],
            'diagnostics' => ['validated_candidates' => $large, 'observed_gallery_count' => $large + $small,
                'rejected_candidates' => array_map(fn ($i) => [
                    'url' => 'https://cdn.example/small-'.$i.'.jpg', 'source' => 'recipe_dom',
                    'reason' => 'dimensions_below_minimum', 'width' => 96, 'height' => 96,
                ], range(1, $small))]];
    }

    public function test_full_pass_diagnoses_failed_reuse_without_repeating_browser_attempts_or_health_penalties(): void
    {
        $exact = $this->recipe('/products/*');
        $compatible = $this->recipe('/notebooks/*', '.alternate img');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/products/second-model';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()
            ->andReturnUsing(function (...$arguments): array {
                $this->assertSame($this->incompleteResult()['images'], $arguments['previousRecipeImages'] ?? $arguments[7]);
                $repairFrom = $arguments['repairFrom'] ?? $arguments[9];
                $this->assertSame('recipe_repair', $repairFrom['mode']);
                $this->assertSame($this->incompleteResult()['diagnostics'], $repairFrom['diagnostics']);

                return [];
            });

        $browser->extract($url, activeRecipeOnly: true);
        $browser->extract($url);

        $this->assertCount(2, $browser->executions, 'Each saved recipe is tried once before diagnosis.');
        $this->assertSame(1, $exact->fresh()->failure_count);
        $this->assertSame(0, $compatible->fresh()->failure_count, 'An unknown path must not damage the proven recipe.');
        $this->assertSame(2, ProductSourceAttempt::query()->where('action', 'extract_gallery')->count());
    }

    public function test_unknown_path_reuses_failed_compatibility_observations_before_training(): void
    {
        $recipe = $this->recipe('/notebooks/*');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/components/new-case';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturn([]);

        $browser->extract($url, activeRecipeOnly: true);
        $browser->extract($url);

        $this->assertCount(1, $browser->executions);
        $this->assertSame(0, $recipe->fresh()->failure_count);
        $this->assertSame(4, $recipe->fresh()->success_count);
        $this->assertNull($recipe->fresh()->compatible_path_patterns);
    }

    public function test_changed_recipe_is_executed_again_before_training(): void
    {
        $recipe = $this->recipe('/products/*');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/products/second-model';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturn([]);

        $browser->extract($url, activeRecipeOnly: true);
        $recipe->update(['recipe' => [...$recipe->recipe, 'collect_selectors' => ['.new-viewer img']]]);
        $browser->extract($url);

        $this->assertCount(2, $browser->executions, 'A changed executable recipe is a new hypothesis.');
        $this->assertSame(2, $recipe->fresh()->failure_count);
    }

    public function test_changed_execution_context_cannot_reuse_the_previous_failure(): void
    {
        $this->recipe('/products/*');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/products/second-model';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturn([]);

        $browser->extract($url, context: ['minimum_image_width' => 700], activeRecipeOnly: true);
        $browser->extract($url, context: ['minimum_image_width' => 1400]);

        $this->assertCount(2, $browser->executions);
    }

    public function test_another_request_executes_the_same_url_and_recipe_again(): void
    {
        $this->recipe('/products/*');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/products/second-model';
        $updates = collect([88101, 88102])->map(fn (int $id): TelegramUpdate => TelegramUpdate::query()->create([
            'update_id' => $id, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing',
        ]));
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturn([]);

        $browser->extract($url, telegramUpdateId: $updates[0]->id, activeRecipeOnly: true);
        $browser->extract($url, telegramUpdateId: $updates[1]->id);

        $this->assertCount(2, $browser->executions);
        $this->assertSame(1, ProductSourceAttempt::query()
            ->where('telegram_update_id', $updates[1]->id)->where('action', 'extract_gallery')->count());
    }

    public function test_a_deferred_failure_is_consumed_once(): void
    {
        $recipe = $this->recipe('/products/*');
        $browser = $this->browser($this->incompleteResult());
        $url = 'https://reuse.example/products/second-model';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->twice()->andReturn([]);

        $browser->extract($url, activeRecipeOnly: true);
        $browser->extract($url);
        $browser->extract($url);

        $this->assertCount(2, $browser->executions, 'A later attempt can observe the page again.');
        $this->assertSame(2, $recipe->fresh()->failure_count);
    }

    public function test_interrupted_reuse_restores_its_observation_for_diagnosis_after_another_source(): void
    {
        $recipe = $this->recipe('/products/*');
        $browser = $this->browser([...$this->incompleteResult(), 'failure_kind' => 'browser_timeout']);
        $url = 'https://reuse.example/products/first-model';
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()
            ->andReturnUsing(function (...$arguments) use ($browser, $url): array {
                $this->assertSame('execution_recovery', $arguments[1]);
                $repairFrom = $arguments['repairFrom'] ?? $arguments[9];
                $this->assertSame('browser_timeout', $repairFrom['failure_kind']);
                $this->assertSame($url, $browser->lastProductPageUrl());
                $this->assertSame('Observed '.$url, $browser->lastSpecificationText());

                return [];
            });

        $browser->extract($url, activeRecipeOnly: true);
        $browser->extract('https://reuse.example/products/second-model', activeRecipeOnly: true);
        $browser->extract($url);

        $this->assertCount(2, $browser->executions);
        $this->assertSame(0, $recipe->fresh()->failure_count);
    }

    public function test_two_known_candidates_both_reuse_before_the_first_can_train(): void
    {
        $this->recipe('/products/*');
        $first = 'https://reuse.example/products/first-model';
        $second = 'https://reuse.example/products/second-model';
        $images = ['https://cdn.example/front.jpg', 'https://cdn.example/side.jpg', 'https://cdn.example/back.jpg'];
        $browser = $this->browser(['by_url' => [
            $first => $this->incompleteResult(),
            $second => [
                'images' => $images,
                'diagnostics' => ['validated_candidates' => 3, 'observed_gallery_count' => 3],
            ],
        ]]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');
        $queue = app(ProductSourcePriority::class)->reuseFirstQueue([
            ['url' => $first, '_preflight_active_recipe' => true],
            ['url' => $second, '_preflight_active_recipe' => true],
        ]);

        $this->assertSame([true, true, false, false], array_column($queue, '_reuse_only'));
        foreach ($queue as $source) {
            $result = $browser->extract($source['url'], activeRecipeOnly: $source['_reuse_only']);
            if ($result !== [] && $browser->isConfirmedGalleryImage($result[0])) {
                break;
            }
        }

        $this->assertSame($images, $result);
        $this->assertCount(2, $browser->executions);
    }

    public function test_success_on_a_new_path_is_reused_on_a_second_different_product_without_training(): void
    {
        $recipe = $this->recipe('/notebooks/*');
        $images = [
            'https://cdn.example/front.jpg',
            'https://cdn.example/side.jpg',
            'https://cdn.example/back.jpg',
        ];
        $browser = $this->browser([
            'images' => $images,
            'learned_recipe' => ['collect_selectors' => ['.gallery img']],
            'diagnostics' => [
                'strict_recipe' => true,
                'validated_candidates' => 3,
                'observed_gallery_count' => 3,
                'validated_image_evidence' => array_map(
                    fn (string $url): array => ['url' => $url, 'source' => 'recipe_dom'],
                    $images,
                ),
            ],
        ]);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');

        $this->assertSame($images, $browser->extract('https://reuse.example/components/first-case'));
        $this->assertSame($images, $browser->extract('https://reuse.example/components/second-case'));

        $this->assertSame(['/components/*'], $recipe->fresh()->compatible_path_patterns);
        $this->assertSame(6, $recipe->fresh()->success_count);
        $this->assertCount(2, $browser->executions, 'Different products require their own real execution.');
        $this->assertDatabaseCount('product_gallery_recipes', 1);
    }

    private function recipe(string $path, string $selector = '.gallery img'): ProductGalleryRecipe
    {
        return ProductGalleryRecipe::query()->create([
            'domain' => 'reuse.example',
            'path_pattern' => $path,
            'status' => 'active',
            'success_count' => 4,
            'failure_count' => 0,
            'recipe' => [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'collect_selectors' => [$selector],
                'actions' => [],
            ],
        ]);
    }

    private function incompleteResult(): array
    {
        return [
            'images' => ['https://cdn.example/first-frame.jpg'],
            'learned_recipe' => ['collect_selectors' => ['.gallery img']],
            'diagnostics' => ['validated_candidates' => 1, 'observed_gallery_count' => 3],
        ];
    }

    private function browser(array $result): BrowserProductGalleryExtractor
    {
        return new class(app(AiSettings::class), app(ProductSearchTimeBudget::class), app(ProductGalleryRecipeResultValidator::class), app(ProductSourceAttemptRecorder::class), app(BrowserRunner::class), app(ProductGalleryRecipeRouter::class), app(HostReputation::class), $result) extends BrowserProductGalleryExtractor
        {
            public array $executions = [];

            public function __construct(
                AiSettings $settings, ProductSearchTimeBudget $timeBudget,
                ProductGalleryRecipeResultValidator $validator, ProductSourceAttemptRecorder $attempts,
                BrowserRunner $runner, ProductGalleryRecipeRouter $router,
                HostReputation $reputation, private readonly array $result,
            ) {
                parent::__construct($settings, $timeBudget, $validator, $attempts, $runner, $router, $reputation);
            }

            public function executeRecipe(
                string $url, array $recipe, int $limit = 20, ?callable $debug = null,
                ?int $telegramUpdateId = null, array $context = [],
            ): array {
                $this->executions[] = ['url' => $url, 'recipe' => $recipe];
                $result = $this->result['by_url'][$url] ?? $this->result;
                (new \ReflectionMethod(BrowserProductGalleryExtractor::class, 'importObservation'))->invoke($this, [
                    'product_page_url' => $url,
                    'product_identity' => null,
                    'specification_text' => 'Observed '.$url,
                    'observed_controls' => [],
                    'click_outcome' => null,
                    'source_identity_assessment' => null,
                    'scout' => [],
                    'images' => $result['images'] ?? [],
                    'identity_unconfirmed' => false,
                ]);

                return $result;
            }
        };
    }
}
