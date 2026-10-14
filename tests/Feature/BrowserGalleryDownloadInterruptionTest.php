<?php

namespace Tests\Feature;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Playwright\BrowserRunner;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\HostReputation;
use App\Services\Products\ProductGalleryRecipeResultValidator;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductSourceAttemptRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrowserGalleryDownloadInterruptionTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_URL = 'https://download.example/products/current';

    protected function setUp(): void
    {
        parent::setUp();

        config(['product-images.browser_fallback.enabled' => true]);
        Http::preventStrayRequests();
    }

    #[DataProvider('downloadCounts')]
    public function test_reuse_only_preserves_recipe_health_without_training_after_browser_image_failures(int $accepted): void
    {
        [$recipe, $compatible] = $this->recipes();
        $result = $this->browserResult($accepted);
        $browser = $this->extractorReturning($recipe, $result);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldNotReceive('train');

        $this->assertSame($result['images'], $browser->extract(
            self::PRODUCT_URL,
            context: ['minimum_verified_images' => 3],
            activeRecipeOnly: true,
        ));

        $this->assertInterruptedWithoutRecipeDamage($recipe, $compatible, $result);
        foreach ($result['images'] as $url) {
            $this->assertTrue($browser->isPartialGalleryImage($url));
            $this->assertFalse($browser->isConfirmedGalleryImage($url));
        }
    }

    #[DataProvider('downloadCounts')]
    public function test_normal_search_requests_execution_recovery_with_browser_evidence_not_recipe_repair(int $accepted): void
    {
        [$recipe, $compatible] = $this->recipes();
        $result = $this->browserResult($accepted);
        $browser = $this->extractorReturning($recipe, $result);
        // Recovery may ask an agent to decide retry versus change. Only this
        // AI boundary is faked; the routing, validator and health writes run.
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()
            ->andReturnUsing(function (...$arguments) use ($recipe, $result): array {
                $this->assertSame(self::PRODUCT_URL, $arguments[0]);
                $this->assertSame('execution_recovery', $arguments[1]);
                $feedback = $arguments['repairFrom'] ?? $arguments[9];
                $this->assertSame('execution_recovery', $feedback['mode']);
                $this->assertSame($recipe->recipe, $feedback['recipe']);
                $this->assertSame($result['diagnostics'], $feedback['diagnostics']);
                $this->assertSame($result['images'], $feedback['images']);
                $this->assertStringContainsString('not evidence the recipe itself is wrong', $feedback['reason']);
                $this->assertSame(1, $recipe->fresh()->failure_count);

                return $result['images'];
            });

        $this->assertSame($result['images'], $browser->extract(
            self::PRODUCT_URL,
            context: ['minimum_verified_images' => 3],
        ));

        $this->assertInterruptedWithoutRecipeDamage($recipe, $compatible, $result);
    }

    public static function downloadCounts(): array
    {
        return [
            'all three browser image requests failed' => [0],
            'two images survived one failed browser request' => [2],
        ];
    }

    private function recipes(): array
    {
        $body = [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'collect_selectors' => ['#gallery img'],
            'actions' => [],
        ];
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'download.example', 'path_pattern' => '/products/*',
            'status' => 'active', 'source_blocked' => false,
            'success_count' => 4, 'failure_count' => 1, 'recipe' => $body,
        ]);
        $compatible = ProductGalleryRecipe::query()->create([
            'domain' => 'download.example', 'path_pattern' => '/other/*',
            'status' => 'active', 'source_blocked' => false,
            'success_count' => 7, 'failure_count' => 0, 'recipe' => $body,
        ]);

        return [$recipe, $compatible];
    }

    private function browserResult(int $accepted): array
    {
        $urls = [
            'https://cdn.download.example/front.jpg',
            'https://cdn.download.example/side.jpg',
            'https://cdn.download.example/back.jpg',
        ];
        $images = array_slice($urls, 0, $accepted);

        return [
            'images' => $images,
            'failure_kind' => null,
            'learned_recipe' => ['collect_selectors' => ['#gallery img'], 'thumbnail_selectors' => [], 'actions' => []],
            'action_trace' => [],
            'diagnostics' => [
                'strict_recipe' => true,
                'partial' => true,
                'interruption_reason' => 'image_load_failed',
                'distinct_dom_assets' => 3,
                'observed_gallery_count' => 3,
                'validated_candidates' => $accepted,
                'validated_image_evidence' => array_map(fn (string $url): array => [
                    'url' => $url, 'width' => 1600, 'height' => 1200, 'source' => 'recipe_dom',
                ], $images),
                // These statuses are observations of browser requests, not
                // inferred from a separate curl/PHP request to the same URL.
                'rejected_candidates' => array_map(fn (string $url): array => [
                    'url' => $url, 'source' => 'recipe_dom', 'reason' => 'image_load_failed',
                    'width' => null, 'height' => null, 'http_status' => 403,
                    'content_type' => 'text/html', 'network_error' => null,
                ], array_slice($urls, $accepted)),
                'action_plan' => ['required' => false, 'complete' => true, 'total_actions' => 0, 'completed_actions' => 0, 'steps' => []],
            ],
        ];
    }

    private function extractorReturning(ProductGalleryRecipe $recipe, array $result): BrowserProductGalleryExtractor
    {
        $browser = Mockery::mock(BrowserProductGalleryExtractor::class, [
            app(AiSettings::class), app(ProductSearchTimeBudget::class),
            app(ProductGalleryRecipeResultValidator::class), app(ProductSourceAttemptRecorder::class),
            app(BrowserRunner::class), app(ProductGalleryRecipeRouter::class),
            app(HostReputation::class),
        ])->makePartial();
        $browser->shouldReceive('executeRecipe')->once()
            ->withArgs(fn (string $url, array $body): bool => $url === self::PRODUCT_URL && $body === $recipe->recipe)
            ->andReturn($result);

        return $browser;
    }

    private function assertInterruptedWithoutRecipeDamage(ProductGalleryRecipe $recipe, ProductGalleryRecipe $compatible, array $result): void
    {
        $recipe->refresh();
        $this->assertSame('active', $recipe->status);
        $this->assertSame(4, $recipe->success_count);
        $this->assertSame(1, $recipe->failure_count);
        $this->assertFalse($recipe->source_blocked);
        $this->assertNull($recipe->source_block_reason);
        $this->assertSame('execution_interrupted', $recipe->last_failure_kind);
        $this->assertStringContainsString('Not counted as a recipe mismatch', $recipe->last_error);

        $compatible->refresh();
        $this->assertSame('active', $compatible->status);
        $this->assertSame(7, $compatible->success_count);
        $this->assertSame(0, $compatible->failure_count);
        $this->assertFalse($compatible->source_blocked);
        $this->assertFalse(ProductSourceAttempt::query()->where('phase', 'compatible_recipe_probe')->exists());

        $run = ProductSourceAttempt::query()->where('phase', 'active_recipe')->where('action', 'extract_gallery')->sole();
        $this->assertSame($recipe->id, $run->output['outcome']['recipe_id']);
        $this->assertTrue($run->output['outcome']['execution_interrupted']);
        $this->assertFalse($run->output['outcome']['selectors_mismatched']);
        $this->assertFalse($run->output['outcome']['fits']);
        $this->assertSame($result['diagnostics'], $run->output['diagnostics']);
    }
}
