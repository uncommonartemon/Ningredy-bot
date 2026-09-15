<?php

namespace Tests\Unit;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Services\Products\ProductGalleryRecipeProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductGalleryRecipeProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_validated_success_for_the_current_recipe_body_is_a_control_page(): void
    {
        $recipe = ProductGalleryRecipe::create(['domain' => 'proof.example', 'path_pattern' => '*',
            'status' => 'active', 'recipe' => ['collect_selectors' => ['#one img']]]);
        $proof = app(ProductGalleryRecipeProof::class);
        ProductSourceAttempt::create(['domain' => 'proof.example', 'product_url' => 'https://proof.example/wrong',
            'actor' => 'playwright', 'phase' => 'active_recipe', 'action' => 'click', 'status' => 'completed']);
        $this->assertNull($proof->provenUrl($recipe, 'https://proof.example/new'));
        $proof->remember($recipe, 'https://proof.example/failed', ['passed' => false]);
        $this->assertNull($proof->provenUrl($recipe, 'https://proof.example/new'));
        $proof->remember($recipe, 'https://proof.example/known', ['passed' => true, 'extracted' => 2]);
        $this->assertSame('https://proof.example/known', $proof->provenUrl($recipe, 'https://proof.example/new'));
        $this->assertNull($proof->provenUrl($recipe, 'https://proof.example/known'));
        $other = ProductGalleryRecipe::create(['domain' => 'proof.example', 'path_pattern' => '/other/*',
            'status' => 'active', 'recipe' => $recipe->recipe]);
        $this->assertNull($proof->provenUrl($other, 'https://proof.example/new'));
        $recipe->update(['recipe' => ['collect_selectors' => ['#different img']]]);
        $this->assertNull($proof->provenUrl($recipe, 'https://proof.example/new'));
    }
}
