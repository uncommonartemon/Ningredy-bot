<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:progress-heartbeat {chatId} {messageId} {label} {maxSeconds} {startedAt} {cancelTelegramUpdateId?}')]
#[Description('Background helper spawned by TelegramProgressReporter::heartbeat() - edits a progress message every ~10s while a single blocking AI call has no other progress callbacks to report. Not meant to be run manually.')]
class TelegramProgressHeartbeat extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        $chatId = (string) $this->argument('chatId');
        $messageId = (int) $this->argument('messageId');
        $label = htmlspecialchars((string) $this->argument('label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $maxSeconds = max(1, (int) $this->argument('maxSeconds'));
        $startedAt = (float) $this->argument('startedAt');
        $cancelTelegramUpdateId = (int) $this->argument('cancelTelegramUpdateId');

        // The parent kills this process (Process::stop()) as soon as the
        // blocking call it's covering for returns. This hard cap only
        // matters if that kill is ever missed - it must not run forever.
        $hardDeadline = $startedAt + $maxSeconds + 120;

        while (microtime(true) < $hardDeadline) {
            sleep(10);
            $elapsed = max(0, (int) ceil(microtime(true) - $startedAt));
            // Pressing the button cannot abort the blocking AI call already
            // in flight (see TelegramProgressReporter::withCancelButton()) -
            // it only stops us from acting on that response once it lands.
            // Reflect that here instead of continuing to promise progress.
            $cancelled = $cancelTelegramUpdateId > 0 && TelegramUpdate::query()
                ->whereKey($cancelTelegramUpdateId)
                ->whereNotNull('cancel_requested_at')
                ->exists();
            $text = $cancelled
                ? "🚫 {$elapsed}с · Отмена запрошена — результат уже начатого запроса к AI будет проигнорирован, когда придёт."
                : "⏳ {$elapsed}с · {$label} · лимит {$maxSeconds} сек.";
            $replyMarkup = ['inline_keyboard' => (! $cancelled && $cancelTelegramUpdateId > 0) ? [[[
                'text' => '❌ Отменить поиск',
                'callback_data' => "search:cancel:{$cancelTelegramUpdateId}",
            ]]] : []];

            try {
                $telegram->editMessageText($chatId, $messageId, $text, parseMode: 'HTML', replyMarkup: $replyMarkup);
            } catch (Throwable) {
                // The parent may already be mid-way through replacing this
                // message with the real final state; keep ticking rather
                // than exiting on one failed edit (e.g. a transient 429).
            }
        }

        return self::SUCCESS;
    }
}
