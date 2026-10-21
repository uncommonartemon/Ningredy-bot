<?php

namespace App\Console\Commands;

use App\Services\ProductSearch\Recovery\RequestGuardian;
use Illuminate\Console\Command;

class GuardRequests extends Command
{
    protected $signature = 'bot:guard';

    protected $description = 'Watch open Telegram requests from outside the search: queued behind another search, stalled, abandoned or undelivered.';

    public function handle(RequestGuardian $guardian): int
    {
        foreach ($guardian->sweep() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
