<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeProof;
use App\Services\Products\ProductGalleryRecipeRouter;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GalleryRecipeCanaryPortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'train_playwright', 'gallery_likely' => true,
            'hidden_images_likely' => true, 'interaction_required' => true,
            'expected_image_count' => 4, 'evidence' => ['Observed product gallery.'],
            'confidence' => 0.95, 'reason' => 'Inspect the gallery.',
        ])->preventStrayPrompts();

        $dependencies = array_map(
            fn ($parameter) => app((string) $parameter->getType()),
            (new \ReflectionClass(ProductImageResolver::class))->getConstructor()->getParameters(),
        );
        $resolver = \Mockery::mock(ProductImageResolver::class, $dependencies)->makePartial();
        $pixels = imagecreatetruecolor(1200, 800);
        ob_start();
        imagejpeg($pixels);
        $bytes = (string) ob_get_clean();
        imagedestroy($pixels);
        $resolver->shouldReceive('download')->andReturnUsing(fn (string $url): array => [
            'bytes' => $bytes, 'source_url' => $url, 'mime_type' => 'image/jpeg',
            'width' => 1200, 'height' => 800, 'confirmed_gallery' => false, 'partial_gallery' => false,
        ]);
        $this->app->instance(ProductImageResolver::class, $resolver);
    }

    public function test_four_frame_repair_cannot_replace_domain_recipe_when_original_nine_frame_page_fails(): void
    {
        $recipe = $this->provenRecipe();
        $oldBody = $recipe->recipe;
        $round = 0;
        ProductGalleryRecipeTrainerAgent::fake(function () use (&$round): array {
            return [...$this->candidate('.gallery img', 4), 'reason' => 'Same executable plan, new explanation '.++$round];
        })->preventStrayPrompts();
        $visited = [];
        $this->browser(function (string $url) use (&$visited): array {
            $visited[] = $url;

            return ['images' => $this->images(4), 'diagnostics' => [
                'observed_gallery_count' => str_ends_with($url, '/original') ? 9 : 4,
            ]];
        });

        $result = app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/products/current', force: true, previousRecipeImages: [],
        );

        $this->assertSame($this->images(4), $result);
        $this->assertSame('https://portability.example/products/original', $visited[1]);
        $this->assertSame(1, count(array_filter($visited, fn ($url) => str_ends_with($url, '/original'))), 'Identical failed plans reuse control evidence.');
        $this->assertSame($oldBody, $recipe->fresh()->recipe);
        $this->assertSame('active', $recipe->fresh()->status);
        $control = ProductGalleryRecipeVersion::latest('id')->firstOrFail()->result['control_replay'];
        $this->assertSame('incompatible', $control['status']);
        $this->assertSame(9, $control['validation']['expected']);
        $this->assertSame(4, $control['validation']['extracted']);
        $this->assertSame(1, ProductGalleryRecipe::count(), 'A failed control alone does not establish a different layout.');
        $this->assertSame(0, ProductGalleryRecipeVersion::where('status', 'promoted')->count());
    }

    public function test_successful_replacements_keep_actual_original_control_proof_across_versions(): void
    {
        $recipe = $this->provenRecipe();
        $visited = [];
        $this->browser(function (string $url) use (&$visited): array {
            $visited[] = $url;
            $count = str_ends_with($url, '/original') ? 9 : (str_ends_with($url, '/second') ? 7 : 4);

            return ['images' => $this->images($count), 'diagnostics' => ['observed_gallery_count' => $count]];
        });
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->candidate('.first-gallery img', 4))->preventStrayPrompts();
        $trainer = app(ProductGalleryRecipeTrainer::class);
        $this->assertCount(4, $trainer->train('https://portability.example/products/current', force: true, previousRecipeImages: []));
        $recipe->refresh();
        $proof = app(ProductGalleryRecipeProof::class);
        $this->assertSame('https://portability.example/products/original',
            $proof->provenUrl($recipe, 'https://portability.example/products/second'));

        ProductGalleryRecipeTrainerAgent::fake(fn (): array => $this->candidate('.second-gallery img', 7))->preventStrayPrompts();
        $this->assertCount(7, $trainer->train('https://portability.example/products/second', force: true, previousRecipeImages: []));
        $this->assertSame([
            'https://portability.example/products/current', 'https://portability.example/products/original',
            'https://portability.example/products/second', 'https://portability.example/products/original',
        ], $visited);
        $this->assertSame('https://portability.example/products/original',
            $proof->provenUrl($recipe->fresh(), 'https://portability.example/products/next'));
        $this->assertSame(1, ProductGalleryRecipe::count());
        $this->assertSame(2, ProductGalleryRecipeVersion::where('status', 'promoted')->count());
    }

    public function test_same_scope_canary_rejection_preserves_current_photos_without_overwriting_recipe(): void
    {
        $recipe = $this->provenRecipe();
        $oldBody = $recipe->recipe;
        // Exercise the trainer's explicit collision guard. The current router
        // normally creates a distinct path row; if it cannot, rejecting the
        // replacement must still retain the validated current-page pictures.
        $this->partialMock(ProductGalleryRecipeRouter::class, function (MockInterface $mock) use ($recipe): void {
            $mock->shouldReceive('recipeForTraining')->twice()->andReturn($recipe);
        });
        $round = 0;
        ProductGalleryRecipeTrainerAgent::fake(function () use (&$round): array {
            return [...$this->candidate('.gallery img', 4),
                'recipe_scope' => ++$round > 1 ? 'page_family' : 'domain',
                'scope_reason' => 'Current page exposes a static media list, while the control has a virtualized viewer.',
            ];
        })->preventStrayPrompts();
        $this->browser(fn (string $url): array => [
            'images' => $this->images(4),
            'diagnostics' => ['observed_gallery_count' => str_ends_with($url, '/original') ? 9 : 4],
        ]);

        $this->assertSame($this->images(4), app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/other-layout/current', force: true, previousRecipeImages: [],
        ));
        $this->assertSame($oldBody, $recipe->fresh()->recipe);
        $this->assertSame('active', $recipe->fresh()->status);
        $this->assertSame(1, ProductGalleryRecipe::count());
        $version = $recipe->versions()->latest('id')->firstOrFail();
        $this->assertSame('rejected', $version->status);
        $this->assertNull($version->promoted_at);
        $this->assertSame('incompatible', $version->result['control_replay']['status']);
    }

    public function test_control_failure_reaches_agent_and_a_repaired_plan_replaces_one_recipe(): void
    {
        $recipe = $this->provenRecipe();
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return $this->candidate(count($prompts) === 1 ? '.fixed img' : '.dynamic img', 4);
        })->preventStrayPrompts();
        $visited = [];
        $this->browser(function (string $url, array $body) use (&$visited): array {
            $visited[] = $url;
            $control = str_ends_with($url, '/original');
            $count = $control && $body['collect_selectors'] === ['.dynamic img'] ? 9 : 4;

            return ['images' => $this->images($count),
                'diagnostics' => ['observed_gallery_count' => $control ? 9 : 4],
                'post_interaction_scout' => ['title' => $control ? 'Control product' : 'Current product',
                    'fragments' => ['<div>Observed gallery</div>']],
            ];
        });

        $this->assertCount(4, app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/other-layout/current', force: true, previousRecipeImages: [],
        ));
        $this->assertCount(2, $prompts);
        $feedback = $prompts[1]['previous_attempt_feedback'];
        $this->assertTrue($feedback['current_page_validation']['passed']);
        $this->assertSame('incompatible', $feedback['portability_validation']['status']);
        $this->assertSame('https://portability.example/products/original', $feedback['portability_validation']['url']);
        $this->assertSame(9, $feedback['portability_validation']['validation']['expected']);
        $this->assertSame('Control product', $feedback['portability_validation']['observation']['title']);
        $this->assertSame('portability_validation', $feedback['recovery']['stage']);
        $this->assertSame(['.dynamic img'], $recipe->fresh()->recipe['collect_selectors']);
        $this->assertSame(1, ProductGalleryRecipe::count());
        $this->assertCount(4, $visited, 'Each changed plan checks current+control once, with no post-loop duplicate.');
        $this->assertSame('promoted', $recipe->versions()->latest('id')->firstOrFail()->status);
    }

    public function test_agent_can_request_a_separate_layout_only_after_receiving_the_failed_control(): void
    {
        $recipe = $this->provenRecipe();
        $oldBody = $recipe->recipe;
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return [...$this->candidate('.gallery img', 4), 'recipe_scope' => 'page_family',
                'scope_reason' => 'Current page exposes a static media list; the control uses a virtualized viewer.',
            ];
        })->preventStrayPrompts();
        $this->browser(fn (string $url): array => [
            'images' => $this->images(4),
            'diagnostics' => ['observed_gallery_count' => str_ends_with($url, '/original') ? 9 : 4],
        ]);

        $this->assertCount(4, app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/other-layout/current', force: true, previousRecipeImages: [],
        ));
        $this->assertCount(2, $prompts, 'Requesting a branch on round one must not bypass the actual control.');
        $this->assertSame('incompatible', $prompts[1]['previous_attempt_feedback']['portability_validation']['status']);
        $this->assertSame($oldBody, $recipe->fresh()->recipe);
        $branch = ProductGalleryRecipe::where('id', '!=', $recipe->id)->firstOrFail();
        $this->assertSame('active', $branch->status);
        $this->assertArrayNotHasKey('recipe_scope', $branch->recipe);
        $this->assertArrayNotHasKey('scope_reason', $branch->recipe);
        $version = $branch->versions()->latest('id')->firstOrFail();
        $this->assertSame('page_family', $version->result['scope_decision']['scope']);
        $this->assertStringContainsString('virtualized viewer', $version->result['scope_decision']['reason']);
        $this->assertSame($recipe->id, app(ProductGalleryRecipeRouter::class)
            ->activeRecipeForUrl('https://portability.example/products/original')->id);
    }

    public function test_page_family_cannot_shadow_the_incompatible_control_with_the_same_path_scope(): void
    {
        $recipe = $this->provenRecipe();
        $prompts = [];
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = json_decode($prompt, true);

            return [...$this->candidate('.gallery img', 4), 'recipe_scope' => 'page_family',
                'scope_reason' => 'Current page exposes a static media list; the control uses a virtualized viewer.',
            ];
        })->preventStrayPrompts();
        $this->browser(fn (string $url): array => [
            'images' => $this->images(4),
            'diagnostics' => ['observed_gallery_count' => str_ends_with($url, '/original') ? 9 : 4],
        ]);
        $this->assertCount(4, app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/products/current', force: true, previousRecipeImages: [],
        ));
        $this->assertSame(1, ProductGalleryRecipe::count());
        $this->assertSame($recipe->id, app(ProductGalleryRecipeRouter::class)
            ->activeRecipeForUrl('https://portability.example/products/original')->id);
        $this->assertStringContainsString('also selects the incompatible control',
            $prompts[2]['previous_attempt_feedback']['portability_validation']['scope_error']);
    }

    public function test_page_family_request_without_any_control_failure_does_not_create_a_branch(): void
    {
        $recipe = $this->provenRecipe();
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => [
            ...$this->candidate('.gallery img', 4), 'recipe_scope' => 'page_family',
            'scope_reason' => 'The current container appears different, but the control has not been checked yet.',
        ])->preventStrayPrompts();
        $this->browser(fn (string $url): array => [
            'images' => $this->images(str_ends_with($url, '/original') ? 9 : 4),
            'diagnostics' => ['observed_gallery_count' => str_ends_with($url, '/original') ? 9 : 4],
        ]);

        $this->assertCount(4, app(ProductGalleryRecipeTrainer::class)->train(
            'https://portability.example/products/current', force: true, previousRecipeImages: [],
        ));
        $this->assertSame(1, ProductGalleryRecipe::count());
        $this->assertSame(['.gallery img'], $recipe->fresh()->recipe['collect_selectors']);
        $this->assertArrayNotHasKey('recipe_scope', $recipe->fresh()->recipe);
        $this->assertNull($recipe->versions()->latest('id')->firstOrFail()->result['scope_decision']);
    }

    public function test_rejected_replacement_photos_do_not_borrow_old_active_recipe_confirmation(): void
    {
        config(['product-images.browser_fallback.enabled' => true]);
        $recipe = $this->provenRecipe();
        $photos = $this->images(4);
        $this->mock(ProductGalleryRecipeTrainer::class)->shouldReceive('train')->once()->andReturnUsing(
            function () use ($recipe, $photos): array {
                $recipe->versions()->create([
                    'domain' => $recipe->domain, 'product_url' => 'https://portability.example/products/current',
                    'trigger' => 'automatic_failure', 'status' => 'rejected',
                    'provider' => 'openai', 'model' => 'test-model',
                    'recipe' => $this->candidate('.new-gallery img', 4),
                    'result' => ['images' => $photos, 'diagnostics' => ['observed_gallery_count' => 4],
                        'control_replay' => ['status' => 'incompatible', 'reason' => 'Original page incomplete.']],
                ]);

                return $photos;
            },
        );
        $dependencies = array_map(
            fn ($parameter) => app((string) $parameter->getType()),
            (new \ReflectionClass(BrowserProductGalleryExtractor::class))->getConstructor()->getParameters(),
        );
        $browser = \Mockery::mock(BrowserProductGalleryExtractor::class, $dependencies)->makePartial();
        $browser->shouldReceive('executeRecipe')->once()->andReturn([
            'images' => [], 'diagnostics' => ['observed_gallery_count' => 4], 'failure_kind' => 'recipe_mismatch',
        ]);

        $this->assertSame($photos, $browser->extract('https://portability.example/products/current'));
        foreach ($photos as $photo) {
            $this->assertFalse($browser->isConfirmedGalleryImage($photo));
            $this->assertTrue($browser->isPartialGalleryImage($photo));
        }
    }

    private function provenRecipe(): ProductGalleryRecipe
    {
        $recipe = ProductGalleryRecipe::create([
            'domain' => 'portability.example', 'path_pattern' => '*', 'status' => 'active', 'success_count' => 2,
            'recipe' => ['collect_selectors' => ['.old img'], 'gallery_present' => true, 'content_confirmed_product' => true],
        ]);
        $proof = app(ProductGalleryRecipeProof::class);
        $proof->remember($recipe, 'https://portability.example/products/original', ['passed' => true, 'extracted' => 9]);
        $proof->remember($recipe, 'https://portability.example/products/recent-regional', ['passed' => true, 'extracted' => 4]);

        return $recipe;
    }

    private function browser(callable $execute): void
    {
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use ($execute): void {
            $mock->shouldReceive('scout')->andReturn(['scout' => [
                'title' => 'Product', 'fragments' => ['<div class="gallery">Photos</div>'],
                'access_gate' => false, 'rate_limited' => false,
            ]]);
            $mock->shouldReceive('executeRecipe')->andReturnUsing($execute);
        });
    }

    private function images(int $count): array
    {
        return array_map(fn (int $n): string => "https://cdn.example/photo-{$n}.jpg", range(1, $count));
    }

    private function candidate(string $selector, int $count): array
    {
        return [
            'gallery_present' => true, 'content_confirmed_product' => true,
            'expected_image_count' => $count, 'expected_count_evidence' => 'Observed current gallery count.',
            'pre_click_selectors' => [], 'collect_selectors' => [$selector], 'thumbnail_selectors' => [],
            'open_selectors' => [], 'next_selectors' => [], 'attributes' => ['src'],
            'max_thumbnail_clicks' => 0, 'max_next_clicks' => 0, 'wait_after_click_ms' => 200,
            'confidence' => 0.95, 'reason' => 'Collect observed product gallery.',
        ];
    }
}
