<?php

namespace App\Services\Telegram;

use App\Models\TelegramUpdate;
use App\Services\Ai\AiUsageReporter;

/**
 * What a request cost and how long it took, as the line under its reply.
 *
 * The draft card builds it itself whenever its sender has none to give: live
 * #1's card came through the request guardian, which passed nothing, and the
 * operator saw neither the time nor the money.
 */
final class RequestUsageFootnote
{
    public function __construct(private readonly AiUsageReporter $usage) {}

    public function for(int $telegramUpdateId, bool $includeSearchDuration = false): string
    {
        $usage = $this->usage->forTelegramUpdate($telegramUpdateId);
        $tokens = (int) ($usage['tokens']['total'] ?? 0);

        if ($tokens <= 0) {
            return '';
        }

        $line = "\n\n🔢 Примерно токенов потрачено: ".number_format($tokens, 0, '.', ' ');

        if ($usage['estimated_cost_usd'] !== null) {
            $line .= sprintf(' (~$%s)', number_format((float) $usage['estimated_cost_usd'], 4));
        }

        if ($includeSearchDuration && ($update = TelegramUpdate::find($telegramUpdateId)) !== null) {
            // Whole request-to-review wall time, including queue waits and
            // automatic repairs, not the sum of overlapping AI call times. A
            // card shown again later keeps the time the request itself took.
            $seconds = max(0, (int) $update->created_at->diffInSeconds($update->processed_at ?? now()));
            $line .= ' ('.($seconds >= 60 ? intdiv($seconds, 60).' мин ' : '').($seconds % 60).' сек)';
        }

        if (($usage['usage_unknown_failures'] ?? 0) > 0) {
            $line .= sprintf(
                ' · %d попытка(и) без usage: итоговая стоимость может быть выше',
                $usage['usage_unknown_failures'],
            );
        }

        return $line;
    }
}
