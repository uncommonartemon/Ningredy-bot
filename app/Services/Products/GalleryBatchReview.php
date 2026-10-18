<?php

namespace App\Services\Products;

/** Explicit per-frame verdicts; an absent/invalid answer is pending, never rejection. */
final readonly class GalleryBatchReview
{
    public function __construct(
        public array $accepted,
        public array $rejected,
        public array $pending,
        public ?string $interruptionReason = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->pending === [];
    }
}
