<?php

namespace App\Services\ProductSearch\Discovery;

use RuntimeException;

final class InvalidDiscoveryResponse extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
