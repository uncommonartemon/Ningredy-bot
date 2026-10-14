<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use Illuminate\Support\Collection;

class ConfirmedProductGalleryVerifier
{
    public const MODE_DEDICATED = 'dedicated';

    public const MODE_AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly ProductGalleryRecipeRouter $recipeRouter,
    ) {}

    /**
     * Recipe evidence establishes where the product gallery is. It does not
     * verify the pixels of another product using the same layout. The caller
     * must still check the current frames before publication. Missing recipe
     * evidence leaves the gallery unconfirmed for the normal fallback path.
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{mode: string, candidates: Collection<int, array<string, mixed>>, cached: bool}
     */
    public function verify(
        string $productPageUrl,
        Collection $candidates,
        ProductDraft $draft,
        int $minimumImages,
        ?int $telegramUpdateId,
    ): array {
        if ($candidates->isEmpty()) {
            return ['mode' => self::MODE_AMBIGUOUS, 'candidates' => $candidates, 'cached' => false];
        }

        $recipe = $this->recipeFor($productPageUrl);
        $mode = $this->mode($recipe, $minimumImages);
        $recipeData = is_array($recipe?->recipe) ? $recipe->recipe : [];
        $contentConfirmed = ($recipeData['content_confirmed_product'] ?? false) === true;

        // Legacy recipe evidence is still usable for routing, never as a
        // replacement for the current gallery's content-and-language check.
        $legacyVisionConfirmed = ($recipeData['content_verified_by_vision'] ?? false) === true;
        $accepted = $contentConfirmed || $legacyVisionConfirmed;

        $this->record(
            $draft,
            $telegramUpdateId,
            $productPageUrl,
            $accepted ? 'accept_recipe_gallery_evidence' : 'reject_unconfirmed_gallery_content',
            $accepted ? 'current_pixels_pending' : 'content_uncertain',
            $candidates->count(),
            $accepted ? $candidates->count() : 0,
        );

        return [
            'mode' => $mode,
            'candidates' => $accepted ? $candidates : collect(),
            'cached' => true,
        ];
    }

    private function recipeFor(string $productPageUrl): ?ProductGalleryRecipe
    {
        return $this->recipeRouter->recipeForUrl($productPageUrl);
    }

    private function mode(?ProductGalleryRecipe $recipe, int $minimumImages): string
    {
        $explicit = $recipe?->recipe['gallery_verification_mode'] ?? null;

        if (in_array($explicit, [self::MODE_DEDICATED, self::MODE_AMBIGUOUS], true)) {
            return $explicit;
        }

        $result = $recipe?->versions()->latest('id')->first()?->result;
        $result = is_array($result) ? $result : [];
        $trace = collect(is_array($result['action_trace'] ?? null) ? $result['action_trace'] : []);
        $expandedViewer = $trace->contains(fn (mixed $item): bool => is_array($item)
            && ($item['clicked'] ?? false) === true
            && (($item['expanded_gallery_visible_after'] ?? false) === true
                || ($item['phase'] ?? null) === 'open_expanded_gallery'));
        $strongCount = max(
            (int) data_get($result, 'diagnostics.gallery_readiness.thumbnail_count', 0),
            (int) data_get($result, 'diagnostics.gallery_readiness.declared_image_count', 0),
            (int) data_get($result, 'diagnostics.gallery_readiness.explicit_image_count', 0),
            (int) data_get($result, 'scout.gallery_readiness.thumbnail_count', 0),
            (int) data_get($result, 'scout.gallery_readiness.declared_image_count', 0),
            (int) data_get($result, 'scout.gallery_readiness.explicit_image_count', 0),
            (int) data_get($result, 'post_interaction_scout.gallery_readiness.thumbnail_count', 0),
            (int) data_get($result, 'post_interaction_scout.gallery_readiness.declared_image_count', 0),
            (int) data_get($result, 'post_interaction_scout.gallery_readiness.explicit_image_count', 0),
        );
        $mode = ($expandedViewer || $strongCount >= max(2, $minimumImages))
            ? self::MODE_DEDICATED
            : self::MODE_AMBIGUOUS;

        if ($recipe) {
            $recipe->refresh();
            $data = is_array($recipe->recipe) ? $recipe->recipe : [];
            $data['gallery_verification_mode'] = $mode;
            unset($data['gallery_batch_verifications']);
            $recipe->update(['recipe' => $data]);
        }

        return $mode;
    }

    private function record(
        ProductDraft $draft,
        ?int $telegramUpdateId,
        string $productPageUrl,
        string $action,
        string $decision,
        int $reviewed,
        int $accepted,
    ): void {
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_draft_id' => $draft->id,
            'product_url' => $productPageUrl,
            'actor' => 'gallery_agent',
            'phase' => 'image_verification',
            'action' => $action,
            'status' => 'completed',
            'decision' => $decision,
            'input' => ['gallery_images' => $reviewed],
            'output' => ['accepted_images' => $accepted],
        ]);
    }
}
