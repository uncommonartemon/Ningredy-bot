<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * cdw.com was trained on three separate days - four rounds, four rounds, one -
 * and every round returned a real gallery. Each session was stopped by the
 * per-source share of the search budget, and the stop wrote nothing but the
 * word "deferred": no candidate, no round history, no reason any of them was
 * rejected. The fourth search would have started at round one again.
 *
 * Rationing which source may keep spending is right. Losing what the spending
 * bought is not.
 */
class InterruptedTrainingResumesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deferred_session_hands_its_rounds_to_the_next_one(): void
    {
        $recipe = $this->recipe();
        $this->deferredVersion($recipe, [
            ['attempt' => 1, 'selectors_tried' => ['collect_selectors' => ['.a']], 'candidate_count' => 3],
            ['attempt' => 2, 'selectors_tried' => ['collect_selectors' => ['.b']], 'candidate_count' => 9],
        ]);

        $resumed = $this->resume($recipe);

        $this->assertCount(2, $resumed['attempts']);
        $this->assertSame(['collect_selectors' => ['.b']], $resumed['feedback']['rejected_recipe']);
        $this->assertSame(9, $resumed['feedback']['candidate_count']);
        $this->assertStringContainsString('attempt_history', $resumed['feedback']['instruction']);
    }

    public function test_only_the_last_rounds_travel(): void
    {
        $recipe = $this->recipe();
        $this->deferredVersion($recipe, collect(range(1, 9))
            ->map(fn (int $n): array => ['attempt' => $n, 'candidate_count' => $n])
            ->all());

        // The whole history carries page diagnostics measured in hundreds of
        // kilobytes; three rounds is the argument, not the archive.
        $this->assertCount(3, $this->resume($recipe)['attempts']);
        $this->assertSame(9, $this->resume($recipe)['feedback']['candidate_count']);
    }

    public function test_a_session_that_was_judged_rather_than_rationed_is_not_resumed(): void
    {
        $recipe = $this->recipe();
        ProductGalleryRecipeVersion::create([
            'product_gallery_recipe_id' => $recipe->id,
            'domain' => $recipe->domain,
            'product_url' => 'https://shop.example/product/1',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'trigger' => 'initial',
            'status' => 'rejected',
            'result' => ['attempts' => [['attempt' => 1, 'candidate_count' => 0]]],
        ]);

        $this->assertSame([], $this->resume($recipe)['attempts']);
        $this->assertNull($this->resume($recipe)['feedback']);
    }

    public function test_a_stale_deferral_is_not_dragged_back_months_later(): void
    {
        $recipe = $this->recipe();
        $version = $this->deferredVersion($recipe, [['attempt' => 1, 'candidate_count' => 4]]);
        $version->forceFill(['created_at' => now()->subDays(30)])->save();

        // The page has had a month to change; its selectors are a guess again.
        $this->assertSame([], $this->resume($recipe)['attempts']);
    }

    public function test_nothing_to_resume_when_the_domain_is_new(): void
    {
        $this->assertSame([], $this->resume($this->recipe())['attempts']);
    }

    private function recipe(): ProductGalleryRecipe
    {
        return ProductGalleryRecipe::create([
            'domain' => 'shop.example',
            'path_pattern' => '/product/*',
            'status' => 'learning',
        ]);
    }

    /** @param array<int, array<string, mixed>> $attempts */
    private function deferredVersion(ProductGalleryRecipe $recipe, array $attempts): ProductGalleryRecipeVersion
    {
        return ProductGalleryRecipeVersion::create([
            'product_gallery_recipe_id' => $recipe->id,
            'domain' => $recipe->domain,
            'product_url' => 'https://shop.example/product/1',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'trigger' => 'initial',
            'status' => 'deferred',
            'result' => ['attempts' => $attempts],
        ]);
    }

    /** @return array{attempts: array<int, mixed>, feedback: array<string, mixed>|null} */
    private function resume(ProductGalleryRecipe $recipe): array
    {
        $method = new ReflectionMethod(ProductGalleryRecipeTrainer::class, 'interruptedTrainingProgress');

        return $method->invoke(app(ProductGalleryRecipeTrainer::class), $recipe);
    }
}
