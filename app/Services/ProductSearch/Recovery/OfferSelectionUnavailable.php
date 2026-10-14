<?php

namespace App\Services\ProductSearch\Recovery;

use RuntimeException;

/** The proposed card is retained; the independent requirement check did not run. */
final class OfferSelectionUnavailable extends RuntimeException {}
