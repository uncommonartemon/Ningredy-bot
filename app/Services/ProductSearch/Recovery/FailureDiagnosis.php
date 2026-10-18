<?php

namespace App\Services\ProductSearch\Recovery;

/** Public operational explanation, never inferred private reasoning. */
final class FailureDiagnosis
{
    public static function make(
        string $stage,
        string $failureKind,
        ?string $serverReason = null,
        ?string $agentComment = null,
        array $blockedBy = [],
        ?string $suggestedNextAction = null,
        array $retained = [],
    ): array {
        return [
            'version' => 1,
            'stage' => $stage,
            'failure_kind' => $failureKind,
            'server_reason' => $serverReason === null ? null : mb_substr($serverReason, 0, 4000),
            'agent_comment' => $agentComment === null ? null : mb_substr($agentComment, 0, 2000),
            'blocked_by' => array_values(array_unique(array_filter($blockedBy, 'is_string'))),
            'suggested_next_action' => $suggestedNextAction,
            'retained' => $retained,
        ];
    }
}
