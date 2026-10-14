<?php

namespace App\Exceptions;

use RuntimeException;

class ProductSearchCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Product search was cancelled by the operator.');
    }
}
