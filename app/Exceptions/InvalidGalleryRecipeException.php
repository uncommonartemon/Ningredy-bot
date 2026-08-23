<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * getMessage() stays in the app locale (Russian) for the operator-facing
 * Telegram progress line. englishMessage is a separate rendering of the same
 * validation failure in English, for previous_attempt_feedback/attempt_history
 * sent back to the (English-prompted) AI trainer - APP_LOCALE=ru otherwise
 * leaks a Russian sentence into that English context.
 *
 * ruleSignature identifies WHICH validation rule(s) failed (e.g.
 * ["actions.*.index"]), independent of the specific value that triggered it
 * (a different out-of-range number still fails the same rule) - this is what
 * lets the trainer loop detect "stuck repeating the same mistake" instead of
 * only ever seeing the message text change with each new bad value.
 */
class InvalidGalleryRecipeException extends RuntimeException
{
    /** @param array<int, string> $ruleSignature */
    public function __construct(
        string $localizedMessage,
        public readonly string $englishMessage,
        public readonly array $ruleSignature = [],
    ) {
        parent::__construct($localizedMessage);
    }
}
