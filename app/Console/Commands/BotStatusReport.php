<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\BotHealth;
use Illuminate\Console\Command;

class BotStatusReport extends Command
{
    protected $signature = 'bot:status';

    protected $description = 'The bot health checks and the request being worked on now, as JSON for the desktop panel.';

    public function handle(BotHealth $health): int
    {
        $active = TelegramUpdate::query()
            ->whereIn('status', ['received', 'transcribed', 'processing', 'delivering'])
            ->whereNull('processed_at')
            ->oldest('id')
            ->first();

        $this->line((string) json_encode([
            'checks' => $health->checks(),
            'active' => $active ? [
                'id' => $active->id,
                'text' => mb_substr(trim((string) $active->text) ?: 'голосовое или фото', 0, 120),
                'minutes' => (int) $active->created_at?->diffInMinutes(now()),
            ] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
