<?php

namespace App\Services\Vision;

/** A paid answer may contain useful observations and a contract issue together. */
final readonly class VisionResponse
{
    public function __construct(
        public array $data,
        public ?string $validationError = null,
    ) {}
}
