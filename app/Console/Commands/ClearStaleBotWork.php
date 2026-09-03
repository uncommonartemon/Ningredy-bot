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

    /** How recent application activity has to be to mean another bot is running. */
    private const ALIVE_WITHIN_SECONDS = 120;

    public function handle(): int
    {
        // The docblock above holds only while this is the single instance, and
        // a second launch is one double-click away - the operator has already
        // ended up with three schedulers running at once. Started next to a
        // working bot, this command would delete the job it is busy with and
        // cancel the request it is answering, so it looks for signs of life
        // first and refuses rather than guess.
        if ($this->anotherBotIsWorking()) {
            $this->warn(
                'Очистка отменена: бот уже запущен и прямо сейчас работает. '
                    .'Закройте предыдущее окно, если хотите начать с чистой очереди.',
            );

            return self::SUCCESS;
        }

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

    /**
     * A job held by a worker is not proof on its own - that is exactly what a
     * killed run leaves behind. Recent writes are: only a process that is still
     * executing keeps producing attempt and AI-run rows.
     */
    private function anotherBotIsWorking(): bool
    {
        if (! Schema::hasTable('jobs') || DB::table('jobs')->whereNotNull('reserved_at')->doesntExist()) {
            return false;
        }

        $aliveSince = now()->subSeconds(self::ALIVE_WITHIN_SECONDS);

        return DB::table('product_source_attempts')->where('created_at', '>', $aliveSince)->exists()
            || DB::table('ai_runs')->where('updated_at', '>', $aliveSince)->exists();
    }
}
