<?php

namespace App\Services\GalleryRecipes\Training;

use App\Services\ProductSearch\Recovery\RecoveryContext;

/** A page requiring diagnosis must not be presented as a proven broken recipe. */
final class RecipeRepairFeedback
{
    public function fromPreviousAttempt(array $attempt): ?array
    {
        if ($attempt === []) {
            return null;
        }
        $mode = $attempt['mode'] ?? 'recipe_repair';
        $uncertain = in_array($mode, ['execution_recovery', 'source_diagnosis'], true);
        $message = ($uncertain
            ? 'Recipe compatibility is unknown; diagnose the recorded attempt: '
            : 'The stored recipe did not pass validation on this page: ')
            .($attempt['reason'] ?? 'unknown reason');
        $recovery = new RecoveryContext('recipe_reuse', $attempt['failure_kind'] ?? $mode, $message,
            ['ReadGalleryPageObservation', 'GetSourceAttemptHistory', 'GetCandidateRejectionDetail',
                'propose_recipe', 'abandon_page'],
            ['previous_recipe' => $attempt['recipe'] ?? null, 'images' => $attempt['images'] ?? []],
            ['action_trace' => $attempt['action_trace'] ?? [], 'diagnostics' => $attempt['diagnostics'] ?? []]);

        return [
            'rejected_recipe' => $uncertain ? null : ($attempt['recipe'] ?? null),
            'previous_recipe' => $attempt['recipe'] ?? null,
            'mode' => $mode,
            'error' => $message,
            'action_trace' => $attempt['action_trace'] ?? [],
            'diagnostics' => $attempt['diagnostics'] ?? [],
            'candidate_count' => count($attempt['images'] ?? []),
            'recovery' => $recovery->toArray(),
            'instruction' => $uncertain
                ? 'Inspect the evidence before deciding whether the existing recipe needs any change. You may propose the same safe plan for a retry, investigate another observed control or end this page attempt. The failure alone does not prove a broken recipe or an unsuitable page.'
                : 'This recipe already works on other pages of this site. Repair the step that '
                    .'failed here rather than describing the gallery from scratch, and keep every step that '
                    .'still executed correctly - a replacement that only fits this page breaks the pages the '
                    .'recipe already opens.',
        ];
    }
}
