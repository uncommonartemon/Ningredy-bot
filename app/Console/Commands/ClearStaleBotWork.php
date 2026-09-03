<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bot is stopped by closing its console window, which kills every worker
 * outright - there is no shutdown hook that could tidy up. Whatever was queued
 * or mid-flight at that moment therefore survives in the database and comes
 * back to life on the next start: a queued job is picked up by a fresh worker,
 * and a draft left half-searched is resurrected later by the stuck-search
 * recovery. From the operator's seat that looks like yesterday's product
 * suddenly answering in the middle of today's search.
 *
 * Running this at startup is both simpler and safer than trying to clean up at
 * shutdown: at the moment the workers start, nothing that was already in the
 * queue can still be alive.
 */
class ClearStaleBotWork extends Command
{
    protected $signature = 'bot:clear-stale';

    protected $description = 'Drop queued jobs and cancel in-flight requests left behind by a previous run.';

    public function handle(): int
    {
        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;

        if ($jobs > 0) {
            DB::table('jobs')->delete();
        }

        // Only updates that never reached a terminal state: a finished request
        // has processed_at set and must keep its real status for the audit log.
        $cancelled = TelegramUpdate::query()
            ->whereNull('processed_at')
            ->update(['status' => 'cancelled', 'processed_at' => now()]);

        $this->info("Очистка перед стартом: снято задач из очереди — {$jobs}, отменено незавершённых запросов — {$cancelled}.");

        return self::SUCCESS;
    }
}
