<?php

namespace App\Services\ProductSearch\Recovery;

/**
 * Evidence handed back to the responsible agent, not a server-selected plan.
 * Actions describe existing capabilities; this object grants no permissions,
 * executes nothing and never changes cancellation, budgets or safety checks.
 */
final readonly class RecoveryContext
{
    public function __construct(
        public string $stage,
        public string $failureKind,
        public string $message,
        public array $availableActions,
        public array $retained = [],
        public array $evidence = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => 'interrupted',
            'stage' => $this->stage,
            'failure_kind' => $this->failureKind,
            'message' => $this->message,
            'semantic_verdict' => 'undetermined',
            'available_actions' => $this->availableActions,
            'retained' => $this->retained,
            'evidence' => $this->evidence,
        ];
    }
}
