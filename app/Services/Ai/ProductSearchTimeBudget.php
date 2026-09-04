<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ProductSearchTimeBudget
{
    /** Where a re-delivered job records that its clock starts over. */
    private const RESTART_KEY = 'product-search-clock-restarted:';

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

    /**
     * A crashed worker's job is released and run again, and that attempt needs
     * its own clock.
     *
     * Seen live on 2026-09-04: a search interrupted at 14:00 resumed after a
     * restart, measured itself against its first AI call from before lunch,
     * announced "time reserve reached" nine seconds in, and closed the draft
     * with no photographs and nothing attempted.
     *
     * Only a genuine re-delivery calls this. PROJECT_STRATEGY reserves a fresh
     * budget for an explicit "continue" press, so a search must never talk
     * itself into one by being slow - an earlier version of this granted a new
     * clock after ten minutes of silence, which would have handed every
     * automatic continuation the button's privilege.
     */
    public function restartSession(?int $telegramUpdateId): void
    {
        if (! $telegramUpdateId) {
            return;
        }

        unset($this->startedAt[$telegramUpdateId]);
        Cache::put(self::RESTART_KEY.$telegramUpdateId, now()->toIso8601String(), now()->addDay());
    }

    private function startedAt(int $telegramUpdateId): CarbonImmutable
    {
        if (isset($this->startedAt[$telegramUpdateId])) {
            return $this->startedAt[$telegramUpdateId];
        }

        $restartedAt = Cache::get(self::RESTART_KEY.$telegramUpdateId);

        if (is_string($restartedAt)) {
            return $this->startedAt[$telegramUpdateId] = CarbonImmutable::parse($restartedAt);
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
