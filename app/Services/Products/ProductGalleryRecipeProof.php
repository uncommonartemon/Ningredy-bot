<?php

namespace App\Services\Products;

use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;

class ProductGalleryRecipeProof
{
    public function fingerprint(array $recipe): string
    {
        // Verification annotates a successful recipe AFTER remember(). It does
        // not change any browser action. Including this annotation erased the
        // control-page proof and let repairs replace working domain recipes
        // without the canary (observed on Lenovo, Acer and Dell).
        unset($recipe['gallery_verification_mode']);

        return $this->legacyFingerprint($recipe);
    }

    private function legacyFingerprint(array $recipe): string
    {
        unset($recipe['expected_image_count'], $recipe['observation_focus_selector']);
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonical($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($recipe), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function remember(ProductGalleryRecipe $recipe, string $url, array $validation, ?int $updateId = null, ?int $versionId = null): void
    {
        if (($validation['passed'] ?? false) !== true) {
            return;
        }
        app(ProductSourceAttemptRecorder::class)->record([
            'telegram_update_id' => $updateId,
            'product_gallery_recipe_version_id' => $versionId,
            'product_url' => $url,
            'actor' => 'server',
            'phase' => 'recipe_validation',
            'action' => 'confirmed_recipe_execution',
            'status' => 'completed',
            'decision' => 'traversal_confirmed',
            'output' => [
                'recipe_id' => $recipe->id,
                'recipe_fingerprint' => $this->fingerprint($recipe->recipe ?? []),
                'validation' => $validation,
            ],
        ]);
    }

    public function provenUrl(ProductGalleryRecipe $recipe, string $exceptUrl): ?string
    {
        return ProductSourceAttempt::query()
            ->where('phase', 'recipe_validation')
            ->where('action', 'confirmed_recipe_execution')
            ->where('status', 'completed')
            ->where('output->recipe_id', $recipe->id)
            // Keep pre-fix proofs usable without rewriting audit history. The
            // legacy digest still has to match this exact executable recipe.
            ->whereIn('output->recipe_fingerprint', array_unique([
                $this->fingerprint($recipe->recipe ?? []),
                $this->legacyFingerprint($recipe->recipe ?? []),
            ]))
            ->where('output->validation->passed', true)
            ->whereNotNull('product_url')
            ->where('product_url', '!=', $exceptUrl)
            ->latest('id')
            ->value('product_url');
    }
}
