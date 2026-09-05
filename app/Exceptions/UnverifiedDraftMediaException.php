<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The photographs are there and the check that should have cleared them is not.
 * Publishing them would make a technical failure indistinguishable from an
 * approval, which is the one thing PROJECT_STRATEGY forbids outright.
 */
class UnverifiedDraftMediaException extends RuntimeException {}
