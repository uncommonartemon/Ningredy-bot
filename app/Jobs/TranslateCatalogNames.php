<?php

namespace App\Jobs;

use App\Services\Catalog\CatalogNames;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Fills the names of categories, groups and characteristics in the languages they lack. */
class TranslateCatalogNames implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /** Several requests in a row are one piece of work. */
    public int $uniqueFor = 120;

    public function __construct(public bool $askAgain = false)
    {
        $this->onQueue('default');
    }

    public function handle(CatalogNames $names): void
    {
        try {
            if ($this->askAgain) {
                $names->forgetUnfilled();
            }
            $names->fill();
        } catch (Throwable $exception) {
            // The names stay as they were; the schedule tries again later.
            report($exception);
        }
    }
}
