<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ProductImageResolver::fetch() when a hop - the very first
 * request or any redirect that followed it - lands on a different host
 * than the one the caller confined this fetch to. Checked before EVERY
 * request in the redirect chain, not only compared against the final
 * result: allowed-host -> other-host -> back-to-allowed-host would pass a
 * start-vs-end comparison while still having actually fetched content from
 * (or through) a host the caller never approved.
 */
class ProductSourceHostDriftException extends RuntimeException
{
    public function __construct(public readonly string $driftedUrl)
    {
        parent::__construct('Redirected off the confined host: '.$driftedUrl);
    }
}
