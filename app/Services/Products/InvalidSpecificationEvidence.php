<?php

namespace App\Services\Products;

use RuntimeException;

final class InvalidSpecificationEvidence extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Invalid specification evidence: '.implode(' ', $violations));
    }
}
