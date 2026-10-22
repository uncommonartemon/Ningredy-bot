<?php

namespace App\Services\Products;

use GuzzleHttp\Psr7\Stream;
use RuntimeException;

/** Limits decompressed cURL writes before they can consume memory or disk. */
class BoundedProductResponseStream extends Stream
{
    public bool $exceeded = false;

    /**
     * @param  bool  $abortQuietly  refuse the write instead of throwing: cURL then ends only
     *                              this transfer, which matters when it shares a loop with others
     */
    public function __construct(private readonly int $maximumBytes, private readonly bool $abortQuietly = false)
    {
        parent::__construct(fopen('php://temp/maxmemory:262144', 'w+b'));
    }

    public function write($string): int
    {
        if ($this->tell() + strlen($string) > $this->maximumBytes) {
            $this->exceeded = true;
            if ($this->abortQuietly) {
                return 0;
            }

            throw new RuntimeException('Product source response exceeds_max_bytes.');
        }

        return parent::write($string);
    }
}
