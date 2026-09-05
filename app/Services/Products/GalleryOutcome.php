<?php

namespace App\Services\Products;

enum GalleryOutcome: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Interrupted = 'interrupted';
    case Rejected = 'rejected';
}
