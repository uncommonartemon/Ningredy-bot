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
        private readonly ProductImageVisionVerifier $visionVerifier,
        private readonly ProductSourceAttemptRecorder $attempts,
    ) {}

    /**
     * A structurally complete Playwright result is not automatically a product
     * gallery. A dedicated media viewer/strong gallery counter needs one
     * representative pixel check per recipe version; an ambiguous carousel is
     * reviewed as one permissive Vision batch for the concrete product.
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

        if ($mode === self::MODE_DEDICATED) {
            return $this->verifyDedicated(
                $recipe,
                $productPageUrl,
                $candidates,
                $draft,
                $telegramUpdateId,
            );
        }

        return $this->verifyAmbiguous(
            $recipe,
            $productPageUrl,
            $candidates,
            $draft,
            $telegramUpdateId,
        );
    }

    /** @return array{mode: string, candidates: Collection<int, array<string, mixed>>, cached: bool} */
    private function verifyDedicated(
        ?ProductGalleryRecipe $recipe,
        string $productPageUrl,
        Collection $candidates,
        ProductDraft $draft,
        ?int $telegramUpdateId,
    ): array {
        $verified = $recipe?->recipe['content_verified_by_vision'] ?? null;

        if (is_bool($verified)) {
            return [
                'mode' => self::MODE_DEDICATED,
                'candidates' => $verified ? $candidates : collect(),
                'cached' => true,
            ];
        }

        $representative = $candidates->first();
        $approved = $this->visionVerifier->select($draft, [$representative], 1, $telegramUpdateId);
        $passed = $approved !== [];
        $this->updateRecipe($recipe, function (array $data) use ($passed): array {
            $data['gallery_verification_mode'] = self::MODE_DEDICATED;
            $data['content_verified_by_vision'] = $passed;

            return $data;
        });
        $this->record(
            $draft,
            $telegramUpdateId,
            $productPageUrl,
            'spot_check_dedicated_gallery',
            $passed ? 'content_confirmed' : 'content_rejected',
            1,
            $passed ? $candidates->count() : 0,
            false,
        );

        if (! $passed) {
            // A dedicated gallery may still start on a non-product poster or
            // another harmless extra frame. Do not poison the whole recipe
            // forever from that one unlucky representative: fall back to the
            // same all-frame review used for ambiguous carousels.
            return $this->verifyAmbiguous(
                $recipe,
                $productPageUrl,
                $candidates,
                $draft,
                $telegramUpdateId,
            );
        }

        return [
            'mode' => self::MODE_DEDICATED,
            'candidates' => $candidates,
            'cached' => false,
        ];
    }

    /** @return array{mode: string, candidates: Collection<int, array<string, mixed>>, cached: bool} */
    private function verifyAmbiguous(
        ?ProductGalleryRecipe $recipe,
        string $productPageUrl,
        Collection $candidates,
        ProductDraft $draft,
        ?int $telegramUpdateId,
    ): array {
        $signature = $this->batchSignature($draft, $candidates);
        $cache = is_array($recipe?->recipe['gallery_batch_verifications'] ?? null)
            ? $recipe->recipe['gallery_batch_verifications']
            : [];
        $cached = is_array($cache[$signature] ?? null) ? $cache[$signature] : null;

        if ($cached !== null && is_array($cached['accepted_asset_keys'] ?? null)) {
            $acceptedKeys = array_fill_keys($cached['accepted_asset_keys'], true);
            $accepted = $candidates
                ->filter(fn (array $candidate): bool => isset($acceptedKeys[$this->assetKey($candidate)]))
                ->values();

            return ['mode' => self::MODE_AMBIGUOUS, 'candidates' => $accepted, 'cached' => true];
        }

        $accepted = collect($this->visionVerifier->selectGalleryFrames(
            $draft,
            $candidates->all(),
            $candidates->count(),
            $telegramUpdateId,
        ))->values();
        $acceptedAssetKeys = $accepted
            ->map(fn (array $candidate): string => $this->assetKey($candidate))
            ->unique()
            ->values()
            ->all();

        $this->updateRecipe($recipe, function (array $data) use ($signature, $acceptedAssetKeys): array {
            unset($data['content_verified_by_vision']);
            $data['gallery_verification_mode'] = self::MODE_AMBIGUOUS;
            $entries = is_array($data['gallery_batch_verifications'] ?? null)
                ? $data['gallery_batch_verifications']
                : [];
            $entries[$signature] = [
                'accepted_asset_keys' => $acceptedAssetKeys,
                'checked_at' => now()->toIso8601String(),
            ];
            $data['gallery_batch_verifications'] = array_slice($entries, -20, null, true);

            return $data;
        });
        $this->record(
            $draft,
            $telegramUpdateId,
            $productPageUrl,
            'batch_check_ambiguous_gallery',
            $accepted->isNotEmpty() ? 'content_filtered' : 'content_rejected',
            $candidates->count(),
            $accepted->count(),
            false,
        );

        return ['mode' => self::MODE_AMBIGUOUS, 'candidates' => $accepted, 'cached' => false];
    }

    private function recipeFor(string $productPageUrl): ?ProductGalleryRecipe
    {
        $host = strtolower((string) parse_url($productPageUrl, PHP_URL_HOST));

        return ProductGalleryRecipe::query()
            ->where('domain', $host)
            ->where('path_pattern', '*')
            ->first();
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

        $this->updateRecipe($recipe, function (array $data) use ($mode): array {
            $data['gallery_verification_mode'] = $mode;

            if ($mode === self::MODE_AMBIGUOUS) {
                unset($data['content_verified_by_vision']);
            }

            return $data;
        });

        return $mode;
    }

    /** @param Collection<int, array<string, mixed>> $candidates */
    private function batchSignature(ProductDraft $draft, Collection $candidates): string
    {
        $assets = $candidates->map(fn (array $candidate): string => $this->assetKey($candidate))->sort()->values()->all();

        return hash('sha256', json_encode([
            'identity' => [$draft->brand, $draft->model, $draft->color],
            'assets' => $assets,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $candidate */
    private function assetKey(array $candidate): string
    {
        return ProductImageStorage::imageAssetKey((string) ($candidate['source_url'] ?? ''));
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutator */
    private function updateRecipe(?ProductGalleryRecipe $recipe, callable $mutator): void
    {
        if (! $recipe) {
            return;
        }

        $recipe->refresh();
        $recipe->update(['recipe' => $mutator(is_array($recipe->recipe) ? $recipe->recipe : [])]);
    }

    private function record(
        ProductDraft $draft,
        ?int $telegramUpdateId,
        string $productPageUrl,
        string $action,
        string $decision,
        int $reviewed,
        int $accepted,
        bool $cached,
    ): void {
        $this->attempts->record([
            'telegram_update_id' => $telegramUpdateId,
            'product_draft_id' => $draft->id,
            'product_url' => $productPageUrl,
            'actor' => 'vision',
            'phase' => 'image_verification',
            'action' => $action,
            'status' => 'completed',
            'decision' => $decision,
            'input' => ['reviewed_images' => $reviewed, 'cached' => $cached],
            'output' => ['accepted_images' => $accepted],
        ]);
    }
}
