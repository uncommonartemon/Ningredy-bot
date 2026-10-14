<?php

namespace Tests\Unit;

use App\Services\GalleryRecipes\RecipeContract;
use App\Services\Products\ProductGalleryRecipeTrainer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GalleryPartialMemoryTest extends TestCase
{
    private function merge(array $previous, array $current): array
    {
        $reflection = new ReflectionClass(ProductGalleryRecipeTrainer::class);

        return $reflection->getMethod('mergePartialResult')->invoke($reflection->newInstanceWithoutConstructor(), $previous, $current);
    }

    private function galleryResult(int $size): array
    {
        $url = 'https://cdn.example/is/image/catalog/frame?wid='.$size.'&hei='.$size;

        return ['images' => [$url], 'diagnostics' => ['validated_image_evidence' => [[
            'url' => $url, 'width' => $size, 'height' => $size, 'source' => 'recipe_dom',
        ]]]];
    }

    public function test_larger_measured_rendition_replaces_an_older_one_without_count_growth(): void
    {
        $larger = $this->galleryResult(1600);
        $larger['post_interaction_scout'] = ['title' => 'Fresh zoom observation'];
        $merged = $this->merge($this->galleryResult(800), $larger);
        $this->assertSame($larger['images'], $merged['images']);
        $this->assertSame($larger['diagnostics']['validated_image_evidence'], $merged['diagnostics']['validated_image_evidence']);
        $this->assertSame($larger['post_interaction_scout'], $merged['post_interaction_scout']);
    }

    public function test_later_smaller_rendition_does_not_destroy_the_best_original(): void
    {
        $larger = $this->galleryResult(1600);
        $merged = $this->merge($larger, $this->galleryResult(800));
        $this->assertSame($larger['images'], $merged['images']);
        $this->assertSame(1600, $merged['diagnostics']['validated_image_evidence'][0]['width']);
    }

    public function test_new_frames_accumulate_and_unmeasured_url_cannot_replace_measured_original(): void
    {
        $larger = $this->galleryResult(1600);
        $current = ['images' => [$this->galleryResult(800)['images'][0], 'https://cdn.example/other.jpg']];
        $merged = $this->merge($larger, $current);
        $this->assertSame([...$larger['images'], 'https://cdn.example/other.jpg'], $merged['images']);
    }

    public function test_iteration_mode_is_preserved_by_the_shared_action_contract(): void
    {
        $reflection = new ReflectionClass(RecipeContract::class);
        $contract = app(RecipeContract::class);
        $this->assertContains('iteration_mode', $contract->recipeActionFields());
        foreach (['elements', 'repeat', 'auto'] as $mode) {
            $action = $reflection->getMethod('sanitizedAction')->invoke($contract, ['iteration_mode' => $mode]);
            $this->assertSame($mode, $action['iteration_mode']);
        }
    }
}
