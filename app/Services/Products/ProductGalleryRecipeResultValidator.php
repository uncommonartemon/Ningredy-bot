<?php

namespace App\Services\Products;

class ProductGalleryRecipeResultValidator
{
    /** @return array{passed: bool, expected: int, extracted: int, reason: string} */
    public function validate(array $recipe, array $result, int $limit = 10): array
    {
        $images = collect($result['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '')
            ->unique()
            ->values();
        $extracted = $images->count();
        $reported = $this->boundedCount($recipe['expected_image_count'] ?? 0, $limit);
        $observed = $this->boundedCount(
            data_get($result, 'diagnostics.observed_gallery_count', 0),
            $limit,
        );
        $expected = max($reported, $observed);
        $galleryPresent = filter_var(
            $recipe['gallery_present'] ?? ($expected > 1),
            FILTER_VALIDATE_BOOL,
        );

        if (! $galleryPresent) {
            return $this->failure($expected, $extracted, 'AI recipe did not confirm a product gallery.');
        }

        if ($expected < 2) {
            return $this->failure(
                $expected,
                $extracted,
                'Gallery size is unknown or contains fewer than two image items.',
            );
        }

        if ($extracted < $expected) {
            return $this->failure(
                $expected,
                $extracted,
                'Gallery incomplete: extracted '.$extracted.' of '.$expected.' expected images.',
            );
        }

        return [
            'passed' => true,
            'expected' => $expected,
            'extracted' => $extracted,
            'reason' => 'Gallery complete: extracted '.$extracted.' of '.$expected.' expected images.',
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

    private function boundedCount(mixed $value, int $limit): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, min(max(1, $limit), (int) $value));
    }
}
