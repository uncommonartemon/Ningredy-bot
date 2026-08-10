<?php

namespace App\Services\Telegram;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Live-updating progress for one long-running operation: instead of one
 * Telegram message per step, this sends a single message and edits it in
 * place. The visible body is a short checklist of stages (step/done/failed);
 * the noisier chatter (info/warning - source-by-source retries, raw error
 * bodies) collects into a collapsed "expandable blockquote" underneath, so
 * the chat isn't flooded but the detail is still one tap away.
 */
class TelegramProgressReporter
{
    /** Minimum seconds between edits of the same message, to stay under Telegram's per-message edit rate limit. */
    private const MIN_EDIT_INTERVAL = 1.2;

    private float $startedAt;

    private ?int $messageId = null;

    private float $lastEditAt = 0.0;

    /** @var array<int, array{label: string, status: string}> Visible checklist lines (steps, done, failed). */
    private array $steps = [];

    /** @var array<int, string> Collapsed detail lines (info, warning). */
    private array $log = [];

    /** @var array<int, array<int, array{text: string, callback_data: string}>>|null */
    private ?array $cancelButtonMarkup = null;

    private ?int $cancelTelegramUpdateId = null;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly string $chatId,
        private readonly bool $enabled = true,
        ?int $existingMessageId = null,
        private readonly ?Closure $onMessageCreated = null,
    ) {
        $this->startedAt = microtime(true);
        $this->messageId = $existingMessageId !== null && $existingMessageId > 0
            ? $existingMessageId
            : null;
    }

    /**
     * Attaches a "cancel" button to the progress message for the duration of
     * one heartbeat() call - see heartbeat()'s own docblock for exactly what
     * pressing it does and does not stop. Cleared automatically once
     * heartbeat() returns, so a step that has already finished never keeps
     * showing a button for a wait that's already over.
     */
    public function withCancelButton(int $telegramUpdateId): static
    {
        $this->cancelTelegramUpdateId = $telegramUpdateId;
        $this->cancelButtonMarkup = [[[
            'text' => '❌ Отменить поиск',
            'callback_data' => "search:cancel:{$telegramUpdateId}",
        ]]];

        return $this;
    }

    public function clearCancelButton(): void
    {
        $this->cancelButtonMarkup = null;
        $this->cancelTelegramUpdateId = null;
        $this->render(force: true);
    }

    public function step(string $title, int $maxSeconds, ?string $detail = null): void
    {
        $this->completePendingSteps();
        $this->steps[] = [
            'label' => "{$this->elapsed()}с · {$title} · лимит {$maxSeconds} сек.",
            'status' => 'pending',
        ];

        if ($detail !== null && $detail !== '') {
            $this->log[] = "{$this->elapsed()}с · {$detail}";
        }

        $this->render();
    }

    public function info(string $message): void
    {
        $this->log[] = "🔎 {$this->elapsed()}с · {$message}";
        $this->render();
    }

    public function warning(string $message): void
    {
        $this->log[] = "⚠️ {$this->elapsed()}с · {$message}";
        $this->render();
    }

    public function done(string $message): void
    {
        $this->completePendingSteps();
        $this->steps[] = [
            'label' => "{$message} · {$this->elapsed()} сек.",
            'status' => 'done',
        ];
        $this->render(force: true);
    }

    /**
     * Some steps are a single blocking call (a web-search AI request that can
     * run for minutes) with no intermediate progress callback to piggyback
     * on - without this, the message just sits frozen on "⏳ 1с ..." for the
     * whole wait, which reads as hung even when it's still working. Runs
     * $callback while a short-lived background process edits the message
     * with a live elapsed counter every ~10s, then stops that process
     * (success or exception) before returning/rethrowing.
     */
    public function heartbeat(string $label, int $maxSeconds, Closure $callback): mixed
    {
        $process = $this->startHeartbeatProcess($label, $maxSeconds);

        try {
            return $callback();
        } finally {
            $process?->stop(2);
            // The cancel button (if any) only makes sense for the wait that
            // just ended - a later step must not keep showing a button whose
            // click would silently do nothing for an already-finished call.
            $this->cancelButtonMarkup = null;
            $this->cancelTelegramUpdateId = null;
        }
    }

    private function startHeartbeatProcess(string $label, int $maxSeconds): ?Process
    {
        // Never spawn a real OS process from the test suite - it would launch
        // a genuine `php artisan` child (and, if a real bot token were ever
        // configured, real Telegram API calls) completely outside of
        // Http::fake()'s reach.
        if (! $this->enabled || $this->chatId === '' || $this->messageId === null || app()->runningUnitTests()) {
            return null;
        }

        try {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'telegram:progress-heartbeat',
                $this->chatId,
                (string) $this->messageId,
                $label,
                (string) $maxSeconds,
                (string) microtime(true),
                (string) ($this->cancelTelegramUpdateId ?? ''),
            ], base_path());
            $process->start();

            return $process;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function failed(string $stage, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $label = "{$this->elapsed()}с · {$stage}: ".mb_substr($message, 0, 700);

        for ($index = count($this->steps) - 1; $index >= 0; $index--) {
            if ($this->steps[$index]['status'] === 'pending') {
                $this->steps[$index] = ['label' => $label, 'status' => 'failed'];
                $this->render(force: true);

                return;
            }
        }

        $this->steps[] = ['label' => $label, 'status' => 'failed'];
        $this->render(force: true);
    }

    /**
     * Called whenever a new stage starts (or the operation ends): every
     * still-"in progress" step so far is, by definition, behind us now, so
     * its hourglass becomes a checkmark instead of staying ⏳ forever.
     */
    private function completePendingSteps(): void
    {
        foreach ($this->steps as &$step) {
            if ($step['status'] === 'pending') {
                $step['status'] = 'done';
            }
        }

        unset($step);
    }

    private function elapsed(): int
    {
        return max(0, (int) ceil(microtime(true) - $this->startedAt));
    }

    private function render(bool $force = false): void
    {
        if (! $this->enabled || $this->chatId === '') {
            return;
        }

        // Skip intermediate edits that arrive faster than Telegram allows;
        // nothing is lost, the buffered lines show up on the next edit.
        if (! $force && $this->messageId !== null && microtime(true) - $this->lastEditAt < self::MIN_EDIT_INTERVAL) {
            return;
        }

        $text = $this->buildText();
        // Always sent explicitly (never omitted): editMessageText leaves a
        // previous keyboard untouched when reply_markup is absent from the
        // payload, so once the cancel window closes (heartbeat() cleared
        // cancelButtonMarkup) this must actively clear it with an empty
        // inline_keyboard, not just stop attaching a new one.
        $replyMarkup = ['inline_keyboard' => $this->cancelButtonMarkup ?? []];

        try {
            if ($this->messageId === null) {
                $response = $this->telegram->sendMessage(
                    $this->chatId,
                    $text,
                    replyMarkup: $replyMarkup,
                    silent: true,
                    parseMode: 'HTML',
                );
                $this->messageId = (int) data_get($response, 'result.message_id') ?: null;

                if ($this->messageId !== null) {
                    ($this->onMessageCreated)?->__invoke($this->messageId);
                }
            } else {
                $this->telegram->editMessageText($this->chatId, $this->messageId, $text, parseMode: 'HTML', replyMarkup: $replyMarkup);
            }

            $this->lastEditAt = microtime(true);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function buildText(): string
    {
        $stepsText = implode("\n", array_map(
            fn (array $step): string => $this->escape($this->statusIcon($step['status'])." {$step['label']}"),
            $this->steps,
        ));

        if ($this->log === []) {
            return mb_substr($stepsText, 0, 4096);
        }

        $wrapper = "\n\n<blockquote expandable></blockquote>";
        $budget = max(0, 4096 - mb_strlen($stepsText) - mb_strlen($wrapper));

        if ($budget <= 0) {
            return mb_substr($stepsText, 0, 4096);
        }

        $logText = implode("\n", array_map($this->escape(...), $this->log));

        if (mb_strlen($logText) > $budget) {
            $logText = '… '.mb_substr($logText, -1 * ($budget - 2));
        }

        return "{$stepsText}\n\n<blockquote expandable>{$logText}</blockquote>";
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'done' => '✅',
            'failed' => '❌',
            default => '⏳',
        };
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
