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
    ) {}

    public static function inspect(array $result, bool $selectorsMissing, array $validation = []): self
    {
        $kind = (string) ($result['failure_kind'] ?? '');
        $interrupted = in_array($kind, [
            'browser_crash', 'browser_timeout', 'browser_process', 'browser_protocol',
            'browser_unavailable', 'host_unreachable', 'host_refusing',
            'download_interrupted', 'execution_interrupted',
        ], true) || ($kind === '' && data_get($result, 'diagnostics.partial') === true);
        $sourceUnconfirmed = $selectorsMissing && ! $interrupted;
        $qualityUnconfirmed = ! $sourceUnconfirmed && ! $interrupted
            && ($validation['needs_source_quality_diagnosis'] ?? false);

        return new self($interrupted, $sourceUnconfirmed,
            $sourceUnconfirmed ? 'source_unconfirmed' : ($qualityUnconfirmed ? 'source_quality_unconfirmed'
                : ($kind ?: ($interrupted ? 'execution_interrupted' : ''))), $qualityUnconfirmed);
    }

    public function mayAffectCompatibility(): bool
    {
        return ! $this->interrupted && ! $this->sourceUnconfirmed && ! $this->qualityUnconfirmed;
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
