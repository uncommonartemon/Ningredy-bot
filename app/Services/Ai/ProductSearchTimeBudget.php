<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Carbon\CarbonImmutable;

class ProductSearchTimeBudget
{
    /** @var array<int, CarbonImmutable> */
    private array $startedAt = [];

    public function __construct(private readonly AiSettings $settings) {}

    public function remainingWorkingSeconds(?int $telegramUpdateId): ?int
    {
        if (! $telegramUpdateId) {
            return null;
        }

        $maxSeconds = $this->settings->searchMaxSeconds();
        $reserveSeconds = min($this->settings->searchReserveSeconds(), max(0, $maxSeconds - 1));
        $elapsed = $this->startedAt($telegramUpdateId)->diffInSeconds(now());

        return max(0, $maxSeconds - $reserveSeconds - $elapsed);
    }

    public function canStart(?int $telegramUpdateId, int $minimumSeconds = 15): bool
    {
        $remaining = $this->remainingWorkingSeconds($telegramUpdateId);

        return $remaining === null || $remaining >= max(1, $minimumSeconds);
    }

    public function timeoutFor(?int $telegramUpdateId, int $configuredSeconds): int
    {
        $configuredSeconds = max(1, $configuredSeconds);
        $remaining = $this->remainingWorkingSeconds($telegramUpdateId);

        return $remaining === null
            ? $configuredSeconds
            : max(1, min($configuredSeconds, $remaining));
    }

    private function startedAt(int $telegramUpdateId): CarbonImmutable
    {
        if (isset($this->startedAt[$telegramUpdateId])) {
            return $this->startedAt[$telegramUpdateId];
        }

        $startedAt = AiRun::query()
            ->where('telegram_update_id', $telegramUpdateId)
            ->whereNotNull('started_at')
            ->oldest('started_at')
            ->value('started_at');

        return $this->startedAt[$telegramUpdateId] = $startedAt
            ? CarbonImmutable::parse($startedAt)
            : CarbonImmutable::now();
    }
}
