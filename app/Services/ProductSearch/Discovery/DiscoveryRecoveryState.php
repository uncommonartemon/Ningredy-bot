<?php

namespace App\Services\ProductSearch\Discovery;

use App\Services\ProductSearch\Recovery\RecoveryContext;

/** Recovery belongs to one search, never to the domain or the saved recipe. */
final class DiscoveryRecoveryState
{
    private ?string $searchKey = null;

    private array $seenFailures = [];

    private ?array $feedback = null;

    private bool $stopped = false;

    public function begin(string $searchKey): void
    {
        if ($this->searchKey !== $searchKey) {
            $this->searchKey = $searchKey;
            $this->succeeded();
        }
    }

    public function failed(InvalidDiscoveryResponse $error): bool
    {
        $this->stopped = isset($this->seenFailures[$error->reason]);
        $this->seenFailures[$error->reason] = true;
        $this->feedback = ['reason' => $error->reason, 'required_correction' => $error->getMessage()];

        return ! $this->stopped;
    }

    public function failedOperation(RecoveryContext $context): bool
    {
        $retryable = $this->failed(new InvalidDiscoveryResponse($context->failureKind, $context->message));
        $this->feedback['recovery'] = $context->toArray();

        return $retryable;
    }

    public function succeeded(): void
    {
        $this->seenFailures = [];
        $this->feedback = null;
        $this->stopped = false;
    }

    public function stopped(): bool
    {
        return $this->stopped;
    }

    public function promptFeedback(): string
    {
        return $this->feedback === null ? '' : 'Previous discovery response failed validation (not a no-results verdict): '
            .json_encode($this->feedback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
