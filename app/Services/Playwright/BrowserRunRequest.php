<?php

namespace App\Services\Playwright;

/** One browser invocation. Contains no recipe routing or publication decision. */
final readonly class BrowserRunRequest
{
    public function __construct(
        public string $url,
        public array $recipe,
        public int $limit,
        public bool $scoutOnly,
        public int $timeoutSeconds,
        public array $context = [],
        public bool $withoutHttp2 = false,
        public ?string $confineNavigationToHost = null,
    ) {}
}
