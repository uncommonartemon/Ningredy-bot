<?php

namespace App\Services\Ai;

use RuntimeException;

class ProductResearchIdleTimeoutException extends RuntimeException
{
    // Marker exception used by the queue retry/error presenter.
}
