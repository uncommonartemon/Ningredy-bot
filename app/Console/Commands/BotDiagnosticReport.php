<?php

namespace App\Console\Commands;

use App\Services\Support\DiagnosticReport;
use Illuminate\Console\Command;

class BotDiagnosticReport extends Command
{
    protected $signature = 'bot:report {--days=7 : How many days of searches to include} {--with-db : Also a copy of the database with its secrets blanked}';

    protected $description = 'The diagnostic archive for the developer (/report in Telegram sends the same file): searches, journal, AI calls, cards, learned site rules, the error log - no secrets.';

    public function handle(DiagnosticReport $report): int
    {
        $path = $report->build(max(1, (int) $this->option('days')), withDatabase: (bool) $this->option('with-db'));
        $this->line($path);

        return self::SUCCESS;
    }
}
