<?php

namespace App\Services\Products;

use App\Services\Ai\AiSettings;

class ProductGalleryRecipeResultValidator
{
    public function __construct(private readonly AiSettings $settings) {}

    /** @return array{passed: bool, expected: int, extracted: int, reason: string} */
    public function validate(
        array $recipe,
        array $result,
        int $limit = 10,
        ?int $minimumSuccessCount = null,
    ): array
    {
        $images = collect($result['images'] ?? [])
            ->filter(fn (mixed $image): bool => is_string($image) && $image !== '')
            // Defense in depth: the JS extractor already dedupes by its own
            // asset key before returning, but this is the same pass/fail
            // count as the AI preflight gate that turned out to be fooled by
            // two renditions of one CDN photo - keep this gate immune to that
            // even if the extractor-side key ever misses a pattern it should.
            ->unique(fn (string $image): string => ProductImageStorage::imageAssetKey($image))
            ->values();
        $extracted = $images->count();
        $minSuccessCount = min(
            $limit,
            max(1, $minimumSuccessCount ?? $this->settings->galleryMinSuccessCount()),
        );
        $recipeExpected = max(0, min($limit, (int) ($recipe['expected_image_count'] ?? 0)));
        $structuralCount = max(
            0,
            (int) data_get($result, 'diagnostics.distinct_dom_assets', 0),
            (int) data_get($result, 'diagnostics.observed_gallery_count', 0),
        );
        // Expected counts are estimates and cannot stand alone: lazy DOM nodes
        // and CDN renditions may inflate them. Once the strict recipe selectors
        // independently expose the same number of distinct physical assets,
        // however, that count is a deterministic completeness target. The
        // category minimum remains the fallback only when structure is unknown.
        $structuralTarget = $recipeExpected >= $minSuccessCount && $structuralCount >= $minSuccessCount
            ? min($recipeExpected, $structuralCount)
            : 0;
        $targetCount = max($minSuccessCount, $structuralTarget);

        $galleryPresent = filter_var(
            $recipe['gallery_present'] ?? ($extracted > 1),
            FILTER_VALIDATE_BOOL,
        );

        if (! $galleryPresent) {
            return $this->failure($targetCount, $extracted, 'AI recipe did not confirm a product gallery.');
        }

        $failureKind = trim((string) ($result['failure_kind'] ?? ''));

        if ($failureKind !== '' || data_get($result, 'diagnostics.partial') === true) {
            return $this->failure(
                $targetCount,
                $extracted,
                'Browser execution was partial'.($failureKind !== '' ? ': '.$failureKind : '.'),
            );
        }

        $validatedCandidates = data_get($result, 'diagnostics.validated_candidates');
        if (is_numeric($validatedCandidates) && (int) $validatedCandidates < $extracted) {
            return $this->failure(
                $targetCount,
                $extracted,
                'Browser returned URLs without technical image validation.',
            );
        }
        if (data_get($result, 'diagnostics.strict_recipe') === true) {
            $evidence = collect(data_get($result, 'diagnostics.validated_image_evidence', []))
                ->filter(fn (mixed $item): bool => is_array($item));
            $recipeDomImages = $evidence
                ->filter(fn (array $item): bool => ($item['source'] ?? null) === 'recipe_dom')
                ->pluck('url')
                ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
                ->unique(fn (string $url): string => ProductImageStorage::imageAssetKey($url))
                ->count();

            if ($recipeDomImages < $extracted) {
                return $this->failure(
                    $targetCount,
                    $extracted,
                    'Strict gallery recipe returned images without recipe-scoped DOM provenance.',
                );
            }
        }

        $incompleteActionPlan = $this->incompleteActionPlan($recipe, $result);

        if ($incompleteActionPlan !== null) {
            return $this->failure($targetCount, $extracted, $incompleteActionPlan);
        }

        if ($extracted < $targetCount) {
            return $this->failure(
                $targetCount,
                $extracted,
                'Gallery incomplete: extracted '.$extracted.' of '.$targetCount.' required images.',
            );
        }

        return [
            'passed' => true,
            'expected' => $targetCount,
            'extracted' => $extracted,
            'reason' => 'Gallery complete: extracted '.$extracted.' of '.$targetCount.' required images.',
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

    private function incompleteActionPlan(array $recipe, array $result): ?string
    {
        $actions = is_array($recipe['actions'] ?? null) ? $recipe['actions'] : [];
        $trace = is_array($result['action_trace'] ?? null) ? $result['action_trace'] : [];

        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action)) {
                return 'Action plan is invalid at step '.$actionIndex.'.';
            }

            $kind = (string) ($action['kind'] ?? '');
            $actionTrace = collect($trace)
                ->filter(fn (mixed $item): bool => is_array($item)
                    && (int) ($item['action_index'] ?? -1) === $actionIndex
                    && ($item['action'] ?? null) === $kind)
                ->values();
            $completedTrace = $actionTrace
                ->filter(fn (array $item): bool => ($item['clicked'] ?? false) === true
                    && ($item['unsafe_control'] ?? false) !== true
                    && ($item['navigation_blocked'] ?? false) !== true
                    && ($item['navigated_away'] ?? false) !== true)
                ->values();

            if ($kind === 'click') {
                if ($completedTrace->isEmpty()) {
                    return 'Action plan incomplete at step '.($actionIndex + 1).': required click was not executed.';
                }

                $selector = (string) ($action['selector'] ?? '');
                $purpose = (string) ($action['purpose'] ?? '');
                $opensGallery = in_array($selector, is_array($recipe['open_selectors'] ?? null)
                    ? $recipe['open_selectors']
                    : [], true)
                    || preg_match('/(?:open|expand|full.?screen|lightbox|zoom|viewer|view.?all)/i', $purpose) === 1;
                $opened = $completedTrace->contains(fn (array $item): bool => ($item['changed'] ?? false) === true
                    || ($item['expanded_gallery_visible_after'] ?? false) === true);

                if ($opensGallery && ! $opened) {
                    return 'Action plan incomplete at step '.($actionIndex + 1).': gallery viewer did not open.';
                }

                continue;
            }

            if ($kind === 'click_each') {
                $selectorMatches = (int) $actionTrace
                    ->max(fn (array $item): int => max(0, (int) ($item['selector_match_count'] ?? 0)));
                $limit = max(1, (int) ($action['limit'] ?? 1));
                $requiredClicks = min($limit, max(1, $selectorMatches));
                $completedClicks = $completedTrace->count();

                if ($completedClicks < $requiredClicks) {
                    return 'Gallery traversal incomplete: clicked '.$completedClicks
                        .' of '.$requiredClicks.' required thumbnail controls.';
                }

                continue;
            }

            if ($kind === 'click_until_no_change') {
                $limit = max(1, (int) ($action['limit'] ?? 1));
                $exhausted = $completedTrace->contains(
                    fn (array $item): bool => ($item['changed'] ?? null) === false,
                );

                if (! $exhausted && $completedTrace->count() < $limit) {
                    return 'Gallery traversal incomplete at step '.($actionIndex + 1)
                        .': next/arrow traversal stopped before exhaustion or its limit.';
                }

                continue;
            }

            return 'Action plan contains unsupported step '.$kind.'.';
        }

        return null;
    }
}
