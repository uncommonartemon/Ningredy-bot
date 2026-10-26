<?php

namespace App\Services\GalleryRecipes;

/**
 * Separates execution failure, an unrecognised page, and failed traversal.
 * No network, database writes or inference from translated page wording.
 */
final readonly class RecipeReplayFailure
{
    private function __construct(
        public bool $interrupted,
        public bool $sourceUnconfirmed,
        public string $kind,
        public bool $qualityUnconfirmed = false,
        /** Every click of the plan hit a target the page keeps hidden: the plan is wrong, not the run. */
        public bool $planBlocked = false,
    ) {}

    public static function inspect(array $result, bool $selectorsMissing, array $validation = []): self
    {
        $kind = (string) ($result['failure_kind'] ?? '');
        // A round whose clicks all landed on hidden targets is not a chance
        // interruption even when the deadline ended it (live #181, #193: three
        // rounds each on ASUS, the hidden zoom the only click, the plan replayed
        // every time because a deadline read as bad luck). The plan needs repair.
        $planBlocked = data_get($result, 'diagnostics.hidden_click_targets', []) !== []
            && ! collect($result['action_trace'] ?? [])
                ->contains(fn (mixed $step): bool => is_array($step) && ($step['clicked'] ?? false) === true);
        $interrupted = ! $planBlocked && (in_array($kind, [
            'browser_crash', 'browser_timeout', 'browser_process', 'browser_protocol',
            'browser_unavailable', 'host_unreachable', 'host_refusing',
            'download_interrupted', 'execution_interrupted',
        ], true) || ($kind === '' && data_get($result, 'diagnostics.partial') === true));
        $sourceUnconfirmed = $selectorsMissing && ! $interrupted && ! $planBlocked;
        $qualityUnconfirmed = ! $sourceUnconfirmed && ! $interrupted && ! $planBlocked
            && ($validation['needs_source_quality_diagnosis'] ?? false);

        return new self($interrupted, $sourceUnconfirmed,
            $planBlocked ? 'hidden_click_targets'
                : ($sourceUnconfirmed ? 'source_unconfirmed' : ($qualityUnconfirmed ? 'source_quality_unconfirmed'
                    : ($kind ?: ($interrupted ? 'execution_interrupted' : '')))), $qualityUnconfirmed, $planBlocked);
    }

    /** A wrong plan says nothing about the page's layout: only a run that executed may count against a recipe. */
    public function mayAffectCompatibility(): bool
    {
        return ! $this->interrupted && ! $this->sourceUnconfirmed && ! $this->qualityUnconfirmed && ! $this->planBlocked;
    }

    public function recoveryMode(): string
    {
        return match (true) {
            $this->interrupted => 'execution_recovery',
            $this->sourceUnconfirmed, $this->qualityUnconfirmed => 'source_diagnosis',
            default => 'recipe_repair',
        };
    }
}
