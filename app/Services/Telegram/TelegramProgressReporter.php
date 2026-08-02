<?php

namespace App\Services\Telegram;

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

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly string $chatId,
        private readonly bool $enabled = true,
    ) {
        $this->startedAt = microtime(true);
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

        try {
            if ($this->messageId === null) {
                $response = $this->telegram->sendMessage($this->chatId, $text, silent: true, parseMode: 'HTML');
                $this->messageId = (int) data_get($response, 'result.message_id') ?: null;
            } else {
                $this->telegram->editMessageText($this->chatId, $this->messageId, $text, parseMode: 'HTML');
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
