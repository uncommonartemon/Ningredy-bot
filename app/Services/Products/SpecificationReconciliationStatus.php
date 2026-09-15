<?php

namespace App\Services\Products;

enum SpecificationReconciliationStatus: string
{
    case Reconciled = 'reconciled';
    case AlreadyReconciled = 'already_reconciled';
    case Disabled = 'disabled';
    case Unavailable = 'unavailable';
    case NotReady = 'not_ready';
}
