<?php

namespace App\Services\Products;

use GuzzleHttp\Psr7\Stream;
use RuntimeException;

/** Limits decompressed cURL writes before they can consume memory or disk. */
class BoundedProductResponseStream extends Stream
{
    public function __construct(private readonly int $maximumBytes)
    {
        parent::__construct(fopen('php://temp/maxmemory:262144', 'w+b'));
    }

    public function write($string): int
    {
        if ($this->tell() + strlen($string) > $this->maximumBytes) {
            throw new RuntimeException('Product source response exceeds_max_bytes.');
        }

        return parent::write($string);
    }
}
