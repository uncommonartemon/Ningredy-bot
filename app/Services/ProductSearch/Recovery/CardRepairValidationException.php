<?php

namespace App\Services\ProductSearch\Recovery;

use Illuminate\Validation\ValidationException;

/** Machine-readable repair scope alongside the original validation failure. */
class CardRepairValidationException extends ValidationException
{
    public array $repairTargets = [];

    public static function forTargets(string $message, array $targets): static
    {
        $exception = static::withMessages(['offer_selection' => [$message]]);
        $exception->repairTargets = $targets;

        return $exception;
    }
}
