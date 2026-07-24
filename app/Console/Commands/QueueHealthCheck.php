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

    public function handle(TelegramClient $telegram): int
    {
        $oldestPending = DB::table('jobs')
            ->whereNull('reserved_at')
            ->orderBy('created_at')
            ->first();

        if (! $oldestPending) {
            Cache::forget(self::CACHE_KEY);

            return self::SUCCESS;
        }

        $waitingSeconds = now()->timestamp - (int) $oldestPending->created_at;

        if ($waitingSeconds < self::STUCK_AFTER_SECONDS || Cache::has(self::CACHE_KEY)) {
            return self::SUCCESS;
        }

        $chatId = TelegramChatState::query()->latest('updated_at')->value('chat_id');

        if (! $chatId) {
            return self::SUCCESS;
        }

        try {
            $minutes = intdiv($waitingSeconds, 60);
            $telegram->sendMessage(
                (string) $chatId,
                "⚠️ Очередь не обрабатывается уже {$minutes} мин. Похоже, воркер (queue:work) не запущен или упал. Перезапустите start-ningredy.bat.",
            );
            Cache::put(self::CACHE_KEY, true, self::ALERT_COOLDOWN_SECONDS);
        } catch (Throwable $exception) {
            report($exception);
        }

        return self::SUCCESS;
    }
}
