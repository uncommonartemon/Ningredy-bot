<?php

namespace App\Services\Telegram;

use Throwable;

class TelegramProgressReporter
{
    private float $startedAt;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly string $chatId,
        private readonly bool $enabled = true,
    ) {
        $this->startedAt = microtime(true);
    }

    public function step(string $title, int $maxSeconds, ?string $detail = null): void
    {
        $suffix = $detail ? "\n{$detail}" : '';
        $this->send("⏳ {$title} · лимит {$maxSeconds} сек.{$suffix}");
    }

    public function info(string $message): void
    {
        $this->send("🔎 {$message}");
    }

    public function warning(string $message): void
    {
        $this->send("⚠️ {$message}");
    }

    public function done(string $message): void
    {
        $seconds = max(1, (int) ceil(microtime(true) - $this->startedAt));
        $this->send("✅ {$message} · {$seconds} сек.");
    }

    public function failed(string $stage, Throwable|string $error): void
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $this->send("❌ {$stage}: ".mb_substr($message, 0, 700));
    }

    private function send(string $message): void
    {
        if (! $this->enabled || $this->chatId === '') {
            return;
        }

        try {
            $this->telegram->sendMessage($this->chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
