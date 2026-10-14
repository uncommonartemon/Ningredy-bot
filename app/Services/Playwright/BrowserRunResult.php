<?php

namespace App\Services\Playwright;

/** Transport result, not a verdict that a gallery or its recipe is valid. */
final readonly class BrowserRunResult
{
    public function __construct(
        public array $payload,
        public string $stderr = '',
        public bool $hasObservation = false,
    ) {}
}
