<?php

namespace App\Console\Commands;

use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\ProcessDraftPhotoActions;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Jobs\TrainProductGalleryRecipe;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
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

    protected $description = 'Cancel stale interactive work, preserving catalog writes and unrelated queued jobs.';

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

        $jobs = 0;
        if (Schema::hasTable('jobs')) {
            // Cancel interactive work, not durable catalog writes. In particular
            // StoreProductImages must survive a restart after draft approval.
            DB::table('jobs')->orderBy('id')->chunkById(100, function ($rows) use (&$jobs): void {
                foreach ($rows as $row) {
                    $payload = json_decode($row->payload, true);
                    $class = is_array($payload) ? data_get($payload, 'data.commandName') : null;
                    $interactive = [
                        ProcessTelegramMessage::class,
                        TranscribeTelegramPhoto::class,
                        TranscribeTelegramVoice::class,
                        ContinueDraftGallerySearch::class,
                        TrainDraftGalleryRecipe::class,
                        TrainProductGalleryRecipe::class,
                        TopUpDraftGalleryPhotos::class,
                        RestageDraftGalleryPhotos::class,
                        ProcessDraftPhotoActions::class,
                    ];
                    if (in_array($class, $interactive, true)) {
                        $jobs += DB::table('jobs')->where('id', $row->id)->delete();
                    }
                }
            });
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
