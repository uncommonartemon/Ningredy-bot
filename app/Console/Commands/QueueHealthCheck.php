<?php

namespace App\Console\Commands;

use App\Models\TelegramChatState;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueHealthCheck extends Command
{
    protected $signature = 'queue:health-check';

    protected $description = 'Alert the admin over Telegram when a queued job has been waiting too long - almost always means queue:work is not running.';

    private const STUCK_AFTER_SECONDS = 180;

    private const ALERT_COOLDOWN_SECONDS = 900;

    private const CACHE_KEY = 'queue-health:alerted';

    /**
     * How long a reserved job may show no application activity at all before it
     * is treated as abandoned. A live product search writes ProductSourceAttempt
     * rows and AiRun rows continuously - training rounds land every few seconds
     * to a couple of minutes - so total silence for this long means the worker
     * that held the job is gone. Generous enough that a single slow AI call
     * cannot trip it.
     */
    private const SILENT_AFTER_SECONDS = 480;

    public function handle(TelegramClient $telegram): int
    {
        $this->releaseAbandonedJobs($telegram);

        $oldestPending = DB::table('jobs')
            ->whereNull('reserved_at')
            ->orderBy('created_at')
            ->first();

        if (! $oldestPending) {
            Cache::forget(self::CACHE_KEY);

            return self::SUCCESS;
        }

        $waitingSeconds = now()->timestamp - (int) $oldestPending->created_at;

        // A job simply queued behind another one is not a dead worker. Only a
        // queue with nothing reserved at all can be unattended - otherwise the
        // wait is just a busy worker, and shouting "restart start-ningredy.bat"
        // sends the admin chasing a problem that does not exist.
        $queueIsBusy = DB::table('jobs')
            ->where('queue', $oldestPending->queue)
            ->whereNotNull('reserved_at')
            ->exists();

        if ($queueIsBusy || $waitingSeconds < self::STUCK_AFTER_SECONDS || Cache::has(self::CACHE_KEY)) {
            return self::SUCCESS;
        }

        $chatId = TelegramChatState::query()->latest('updated_at')->value('chat_id');

        if (! $chatId) {
            return self::SUCCESS;
        }

        try {
            $minutes = intdiv($waitingSeconds, 60);
            $queue = (string) ($oldestPending->queue ?? 'default');
            $telegram->sendMessage(
                (string) $chatId,
                "⚠️ Очередь {$queue} не обрабатывается уже {$minutes} мин. Похоже, соответствующий воркер (queue:work) не запущен или упал. Перезапустите start-ningredy.bat.",
            );
            Cache::put(self::CACHE_KEY, true, self::ALERT_COOLDOWN_SECONDS);
        } catch (Throwable $exception) {
            report($exception);
        }

        return self::SUCCESS;
    }

    /**
     * A worker killed mid-job (memory exhaustion, a closed console) leaves its
     * job reserved, and the database queue cannot tell that apart from a search
     * still legitimately running - so nothing touches it until retry_after
     * elapses, which must exceed ProcessTelegramMessage's own 35-minute ceiling.
     * The check above only ever looked at unreserved jobs, so this exact failure
     * was invisible to it and the admin simply saw the bot go quiet.
     *
     * Application activity is the accurate liveness signal the queue lacks:
     * releasing only jobs whose search has written nothing at all for a while
     * hands them to the next worker in seconds instead of half an hour, without
     * ever double-running a search that is merely slow.
     */
    private function releaseAbandonedJobs(TelegramClient $telegram): void
    {
        $reserved = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<=', now()->timestamp - self::SILENT_AFTER_SECONDS)
            ->get();

        foreach ($reserved as $job) {
            $updateId = $this->telegramUpdateIdFor($job);

            if ($updateId === null || $this->searchIsAlive($updateId)) {
                continue;
            }

            DB::table('jobs')->where('id', $job->id)->update(['reserved_at' => null]);

            try {
                $chatId = TelegramChatState::query()->latest('updated_at')->value('chat_id');

                // Several schedule:work processes can be running at once (easy
                // to end up with after manual restarts), and each would report
                // the same release. Claiming the notice keeps the chat to one
                // message per job whatever the environment looks like.
                $announced = ! Cache::add('queue-health:released:'.$job->id, true, self::ALERT_COOLDOWN_SECONDS);

                if ($chatId && ! $announced) {
                    $telegram->sendMessage(
                        (string) $chatId,
                        '♻️ Поиск оборвался (воркер не отвечает) — перезапускаю его автоматически, ждать не нужно.',
                    );
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function searchIsAlive(int $telegramUpdateId): bool
    {
        $silentSince = now()->subSeconds(self::SILENT_AFTER_SECONDS);

        return DB::table('product_source_attempts')
            ->where('telegram_update_id', $telegramUpdateId)
            ->where('created_at', '>', $silentSince)
            ->exists()
            || DB::table('ai_runs')
                ->where('telegram_update_id', $telegramUpdateId)
                ->where('updated_at', '>', $silentSince)
                ->exists();
    }

    /**
     * Read from the serialized command rather than unserializing it: the payload
     * is data written by another process, and this command must stay safe to run
     * even when a queued class has since been renamed or removed.
     */
    private function telegramUpdateIdFor(object $job): ?int
    {
        $command = (string) data_get(json_decode((string) $job->payload, true), 'data.command');

        return preg_match('/telegramUpdateId";i:(\d+);/', $command, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
