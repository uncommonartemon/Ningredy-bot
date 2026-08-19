<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * getMessage() stays in the app locale (Russian) for the operator-facing
 * Telegram progress line. englishMessage is a separate rendering of the same
 * validation failure in English, for previous_attempt_feedback/attempt_history
 * sent back to the (English-prompted) AI trainer - APP_LOCALE=ru otherwise
 * leaks a Russian sentence into that English context.
 */
class InvalidGalleryRecipeException extends RuntimeException
{
    public function __construct(string $localizedMessage, public readonly string $englishMessage)
    {
        parent::__construct($localizedMessage);
    }
}
