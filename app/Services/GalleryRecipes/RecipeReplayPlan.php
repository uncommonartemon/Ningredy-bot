<?php

namespace App\Services\GalleryRecipes;

/** Keeps the proven opening prefix when a revised plan starts from a fresh page. */
class RecipeReplayPlan
{
    /**
     * A correction is proposed from post-interaction evidence but executed in
     * a fresh browser. Preserve the successful click prefix that produced the
     * observed state unless the correction already contains that exact step.
     * This is generic replay protection, not a domain-specific selector rule.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $previousCandidate
     * @param  array<string, mixed>|null  $previousResult
     * @return array<string, mixed>
     */
    public function makeRecipeReplayable(
        array $candidate,
        ?array $previousCandidate,
        ?array $previousResult,
    ): array {
        if (! $previousCandidate || ! $previousResult) {
            return $candidate;
        }

        $previousActions = is_array($previousCandidate['actions'] ?? null)
            ? $previousCandidate['actions']
            : [];
        $trace = collect(is_array($previousResult['action_trace'] ?? null)
            ? $previousResult['action_trace']
            : []);
        $candidateActions = collect(is_array($candidate['actions'] ?? null)
            ? $candidate['actions']
            : []);

        $successfulPrefix = [];
        foreach ($previousActions as $actionIndex => $action) {
            if (! is_array($action) || ($action['kind'] ?? null) !== 'click') {
                break;
            }

            $selector = (string) ($action['selector'] ?? '');
            $purpose = (string) ($action['purpose'] ?? '');
            $opensGallery = in_array($selector, $previousCandidate['open_selectors'] ?? [], true)
                || preg_match('/(?:open|expand|full.?screen|lightbox|zoom|viewer|view.?all)/i', $purpose) === 1;
            $worked = $trace->contains(fn (mixed $item): bool => is_array($item)
                && (int) ($item['action_index'] ?? -1) === $actionIndex
                && ($item['action'] ?? null) === 'click'
                && ($item['clicked'] ?? false) === true
                && ($item['unsafe_control'] ?? false) !== true
                && ($item['navigation_blocked'] ?? false) !== true
                && ($item['navigated_away'] ?? false) !== true
                && (! $opensGallery
                    || ($item['changed'] ?? false) === true
                    || ($item['expanded_gallery_visible_after'] ?? false) === true));
            if (! $worked) {
                break;
            }

            $successfulPrefix[] = $action;
        }

        if ($successfulPrefix === []) {
            return $candidate;
        }

        $prefixKeys = collect($successfulPrefix)
            ->map(fn (array $action): string => 'click|'.($action['selector'] ?? '').'|'.($action['index'] ?? 0))
            ->all();
        $remainingActions = $candidateActions
            ->reject(function (array $action) use ($prefixKeys): bool {
                $key = ($action['kind'] ?? '').'|'.($action['selector'] ?? '').'|'.($action['index'] ?? 0);

                return in_array($key, $prefixKeys, true);
            })
            ->values()->all();
        $candidate['actions'] = [
            ...$successfulPrefix,
            ...$remainingActions,
        ];
        $candidate['open_selectors'] = collect($candidate['open_selectors'] ?? [])
            ->merge($previousCandidate['open_selectors'] ?? [])
            ->filter(fn (mixed $selector): bool => is_string($selector))
            ->unique()->values()->all();
        $candidate['pre_click_selectors'] = collect($candidate['pre_click_selectors'] ?? [])
            ->merge($previousCandidate['pre_click_selectors'] ?? [])
            ->filter(fn (mixed $selector): bool => is_string($selector))
            ->unique()->values()->all();

        return $candidate;
    }
}
