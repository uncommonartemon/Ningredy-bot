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
    ): array {
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

        if (($recipe['content_confirmed_product'] ?? false) !== true) {
            return $this->failure(
                $targetCount,
                $extracted,
                'Gallery content remains uncertain: the agent did not confirm one coherent product gallery.',
            );
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

        $qualityGap = $this->unresolvedEnlargementControl($recipe, $result);
        if ($qualityGap !== null) {
            return $this->failure($targetCount, $extracted, $qualityGap);
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

    private function unresolvedEnlargementControl(array $recipe, array $result): ?string
    {
        $needsPerFrameFollowup = collect($recipe['actions'] ?? [])->contains(
            fn (mixed $action): bool => is_array($action)
                && ($action['kind'] ?? null) === 'click_each'
                && trim((string) ($action['after_each_selector'] ?? '')) === '',
        );

        return $needsPerFrameFollowup ? $this->lowResolutionEnlarger($result) : null;
    }

    private function lowResolutionEnlarger(array $result): ?string
    {
        $evidence = collect(data_get($result, 'diagnostics.validated_image_evidence', []))
            ->filter(fn (mixed $item): bool => is_array($item) && is_numeric($item['width'] ?? null));
        if ($evidence->count() < 2 || (int) $evidence->min('width') > 1000) {
            return null;
        }

        return $this->observedEnlargerSelector($result);
    }

    private function observedEnlargerSelector(array $result): ?string
    {
        $control = collect(data_get($result, 'post_interaction_scout.action_candidates', []))
            ->first(function (mixed $item): bool {
                $signal = is_array($item) ? implode(' ', [
                    $item['selector'] ?? '', $item['text'] ?? '',
                    $item['aria_label'] ?? '', $item['title'] ?? '',
                ]) : '';

                return preg_match('/(?:zoom.{0,30}plus|plus.{0,30}zoom|enlarge|magnif|maximi[sz]e)/i', $signal) === 1;
            });
        $selector = is_array($control) ? trim((string) ($control['selector'] ?? '')) : '';

        return $selector === '' ? null
            : 'Higher-resolution control '.$selector.' remained unused for each low-resolution frame; '
                .'attach it as click_each.after_each_selector and verify the larger observed URLs.';
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
            $primaryTrace = $actionTrace
                ->reject(fn (array $item): bool => ($item['after_each'] ?? false) === true)
                ->values();
            $completedTrace = $primaryTrace
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

                // The opening click may carry the zoom control too, so the frame
                // it reveals reaches the same resolution as the ones an arrow
                // reaches later. One press, so one follow-up sequence is owed.
                $afterEachError = $this->incompleteAfterEachAction($action, $actionTrace, 1);
                if ($afterEachError !== null) {
                    return $afterEachError;
                }

                continue;
            }

            if ($kind === 'click_each') {
                $selectorMatches = (int) $actionTrace
                    ->max(fn (array $item): int => max(0, (int) ($item['selector_match_count'] ?? 0)));
                $limit = max(1, (int) ($action['limit'] ?? 1));
                // Several matched controls are walked one each, so the plan can
                // never need more clicks than there are elements. A single match
                // is the opposite shape - a next/prev arrow re-pressed limit
                // times - and capping it at the match count silently declared a
                // one-click traversal "complete" while the recipe's own declared
                // frames were still unreached (seen live on a B&H modal recipe:
                // 4 declared frames, 1 arrow press, validation passed).
                $requiredClicks = $selectorMatches > 1
                    ? min($limit, $selectorMatches)
                    : $limit;
                $completedClicks = $completedTrace->count();
                // The same single control legitimately runs out of frames before
                // its limit; an unchanged press is that exhaustion, exactly as
                // click_until_no_change treats it below.
                $exhausted = $selectorMatches <= 1 && $completedTrace->contains(
                    fn (array $item): bool => ($item['changed'] ?? null) === false,
                );

                if (! $exhausted && $completedClicks < $requiredClicks) {
                    return 'Gallery traversal incomplete: clicked '.$completedClicks
                        .' of '.$requiredClicks.' required thumbnail controls.';
                }

                // An exhausted control never reached the remaining repetitions,
                // so only the presses that actually happened owe a follow-up.
                $afterEachError = $this->incompleteAfterEachAction(
                    $action,
                    $actionTrace,
                    $exhausted ? $completedClicks : $requiredClicks,
                );
                if ($afterEachError !== null) {
                    return $afterEachError;
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

    private function incompleteAfterEachAction(array $action, mixed $actionTrace, int $requiredClicks): ?string
    {
        $selector = trim((string) ($action['after_each_selector'] ?? ''));
        if ($selector === '') {
            return null;
        }

        return $this->missingAfterEachFollowup($selector, $action, $actionTrace, $requiredClicks);
    }

    private function missingAfterEachFollowup(string $selector, array $action, mixed $actionTrace, int $requiredClicks): ?string
    {
        $limit = max(1, (int) ($action['after_each_limit'] ?? 1));
        for ($repetition = 0; $repetition < $requiredClicks; $repetition++) {
            if (! $this->afterEachFollowupCompleted($actionTrace, $repetition, $limit)) {
                return 'Gallery traversal incomplete after thumbnail '.($repetition + 1)
                    .': nested follow-up '.$selector.' was not completed.';
            }
        }

        return null;
    }

    private function afterEachFollowupCompleted(mixed $actionTrace, int $repetition, int $limit): bool
    {
        $followups = $actionTrace->filter(
            fn (array $item): bool => ($item['after_each'] ?? false) === true
                && (int) ($item['parent_repetition'] ?? -1) === $repetition
                && ($item['clicked'] ?? false) === true
                && ($item['unsafe_control'] ?? false) !== true
                && ($item['navigation_blocked'] ?? false) !== true
                && ($item['navigated_away'] ?? false) !== true,
        );

        // A zoom control that vanishes at maximum magnification has finished its
        // job, but it was read as an unfinished plan and failed the whole recipe:
        // the missing-selector entry it leaves carries clicked=false, so it
        // counted for nothing. It only means exhaustion after the control worked
        // at least once - a selector that was never there at all is still a
        // broken step.
        $disappeared = $followups->isNotEmpty() && $actionTrace->contains(
            fn (array $item): bool => ($item['after_each'] ?? false) === true
                && (int) ($item['parent_repetition'] ?? -1) === $repetition
                && ($item['selector_missing'] ?? false) === true,
        );
        $exhausted = $disappeared || $followups->contains(
            fn (array $item): bool => ($item['changed'] ?? null) === false,
        );

        return $exhausted || $followups->count() >= $limit;
    }
}
