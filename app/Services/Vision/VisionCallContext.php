<?php

namespace App\Services\Vision;

/** Invocation scope, never a saved product/gallery verdict. */
final readonly class VisionCallContext
{
    public function __construct(
        public ?int $telegramUpdateId = null,
        public ?int $timeoutSeconds = null,
        public bool $useSearchBudget = true,
        public ?string $provider = null,
        public ?string $model = null,
    ) {}
}
