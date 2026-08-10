<?php

namespace App\Services\Products;

use App\Services\Ai\AiSettings;

class ProductGalleryRecipeResultValidator
{
    public function __construct(private readonly AiSettings $settings) {}

    /** @return array{passed: bool, expected: int, extracted: int, reason: string} */
    public function validate(array $recipe, array $result, int $limit = 10): array
    {
        $images = collect($result['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '')
            // Defense in depth: the JS extractor already dedupes by its own
            // asset key before returning, but this is the same pass/fail
            // count as the AI preflight gate that turned out to be fooled by
            // two renditions of one CDN photo - keep this gate immune to that
            // even if the extractor-side key ever misses a pattern it should.
            ->map(fn (string $image): string => ProductImageStorage::normalizeCandidateUrl($image))
            ->unique()
            ->values();
        $extracted = $images->count();
        // The AI's expected_image_count and the browser's observed thumbnail-node
        // count are both estimates prone to overcounting (duplicate/lazy-loaded
        // thumbnail nodes, "X of N" markers on non-photo slides), so they are not
        // used as a pass/fail gate. A trained recipe that reliably produces at
        // least this many verified distinct images counts as a full success.
        $minSuccessCount = min($limit, $this->settings->galleryMinSuccessCount());

        $galleryPresent = filter_var(
            $recipe['gallery_present'] ?? ($extracted > 1),
            FILTER_VALIDATE_BOOL,
        );

        if (! $galleryPresent) {
            return $this->failure($minSuccessCount, $extracted, 'AI recipe did not confirm a product gallery.');
        }

        if ($extracted < $minSuccessCount) {
            return $this->failure(
                $minSuccessCount,
                $extracted,
                'Gallery incomplete: extracted '.$extracted.' of '.$minSuccessCount.' minimum required images.',
            );
        }

        return [
            'passed' => true,
            'expected' => $minSuccessCount,
            'extracted' => $extracted,
            'reason' => 'Gallery complete: extracted '.$extracted.' images (minimum '.$minSuccessCount.').',
        ];
    }

    /** @return array{passed: false, expected: int, extracted: int, reason: string} */
    private function failure(int $expected, int $extracted, string $reason): array
    {
        return [
            'passed' => false,
            'expected' => $expected,
            'extracted' => $extracted,
            'reason' => $reason,
        ];
    }
}
