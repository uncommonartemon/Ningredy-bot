<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Carbon\CarbonImmutable;

class ProductSearchTimeBudget
{
    /**
     * Silence longer than this between one AI call finishing and the next
     * starting means the search was interrupted rather than merely slow, and
     * whatever follows is a new session with its own clock. Comfortably above
     * the pause between calls of a search that is actually running, and far
     * below the hour a released job typically waits.
     */
    private const RESUME_AFTER_IDLE_SECONDS = 600;

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
     * When the current working session began - not when this request was first
     * ever touched.
     *
     * A worker that dies mid-search leaves its job to be released and picked up
     * again later. Measuring from the very first AI call meant such a job
     * resumed with a budget spent hours ago: seen live on 2026-09-04, a search
     * interrupted at 14:00 resumed after a restart, announced "time reserve
     * reached" nine seconds in, and closed the draft with no photographs at all.
     * Work that resumes an hour later is a new session and gets its own clock.
     *
     * The gap is measured from when the previous call finished, so a single
     * legitimately long call - research can run for minutes - never looks like
     * an interruption.
     */
    private function startedAt(int $telegramUpdateId): CarbonImmutable
    {
        if (isset($this->startedAt[$telegramUpdateId])) {
            return $this->startedAt[$telegramUpdateId];
        }

        $runs = AiRun::query()
            ->where('telegram_update_id', $telegramUpdateId)
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->get(['started_at', 'completed_at']);

        $sessionStart = null;
        $previousEnd = null;

        foreach ($runs as $run) {
            $startedAt = CarbonImmutable::parse($run->started_at);

            // Explicit subtraction rather than diffInSeconds(): the latter is
            // signed by argument order, and getting that backwards silently
            // compares a negative number against the threshold, which is never
            // greater - the guard would exist and never fire.
            $idleSeconds = $previousEnd === null ? 0 : $startedAt->getTimestamp() - $previousEnd->getTimestamp();

            if ($previousEnd === null || $idleSeconds > self::RESUME_AFTER_IDLE_SECONDS) {
                $sessionStart = $startedAt;
            }

            $previousEnd = $run->completed_at ? CarbonImmutable::parse($run->completed_at) : $startedAt;
        }

        return $this->startedAt[$telegramUpdateId] = $sessionStart ?? CarbonImmutable::now();
    }
}
