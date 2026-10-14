<?php

namespace App\Services\ProductSearch\Research;

use RuntimeException;
use Throwable;

/** A failed delivery/format, never a verdict that the requested product is absent. */
final class InvalidResearchResponse extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly array $evidence,
        public readonly ?array $usage,
        public readonly string $invocationId,
        public readonly bool $recoverable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Product research response requires recovery: '.$reason, previous: $previous);
    }
}
