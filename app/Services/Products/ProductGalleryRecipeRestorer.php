<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductGalleryRecipeRestorer
{
    public function canRestore(ProductGalleryRecipeVersion $version): bool
    {
        if ($version->status !== 'promoted' || ! $version->promoted_at
            || ! is_array($version->recipe) || $version->recipe === []
            || ! $version->galleryRecipe || $version->domain !== $version->galleryRecipe->domain) {
            return false;
        }

        return data_get($version->result, 'validation.passed') === true
            || ProductSourceAttempt::query()->where('phase', 'recipe_validation')
                ->where('action', 'confirmed_recipe_execution')->where('status', 'completed')
                ->where('output->recipe_id', $version->product_gallery_recipe_id)
                ->where('output->recipe_fingerprint', app(ProductGalleryRecipeProof::class)->fingerprint($version->recipe))
                ->where('output->validation->passed', true)->exists();
    }

    public function restore(ProductGalleryRecipeVersion $version): void
    {
        DB::transaction(function () use ($version): void {
            $version = ProductGalleryRecipeVersion::query()->lockForUpdate()->findOrFail($version->id);
            $recipe = ProductGalleryRecipe::query()->lockForUpdate()->find($version->product_gallery_recipe_id);
            $version->setRelation('galleryRecipe', $recipe);
            if (! $this->canRestore($version)) {
                throw ValidationException::withMessages(['recipe' => __('Восстановить можно только ранее опубликованную версию с доказанной успешной проверкой.')]);
            }
            $previous = $recipe->recipe;
            $recipe->update(['recipe' => $version->recipe, 'status' => 'active', 'last_error' => null]);
            ProductGalleryRecipeVersion::query()->create([
                'product_gallery_recipe_id' => $recipe->id, 'domain' => $recipe->domain,
                'product_url' => $version->product_url, 'trigger' => 'manual_rollback', 'status' => 'promoted',
                'provider' => $version->provider, 'model' => $version->model,
                'previous_recipe' => $previous, 'recipe' => $version->recipe,
                'result' => ['restored_version_id' => $version->id, 'validation' => data_get($version->result, 'validation')],
                'score' => $version->score, 'promoted_at' => now(),
            ]);
        });
    }
}
