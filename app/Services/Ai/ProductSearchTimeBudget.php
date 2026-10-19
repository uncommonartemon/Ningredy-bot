<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\TelegramUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductSearchTimeBudget
{
    /** Where a re-delivered job records that its clock starts over. */
    private const RESTART_KEY = 'product-search-clock-restarted:';

    private const START_PAYLOAD_KEY = '_product_search_started_at';

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

    public function elapsedSeconds(?int $telegramUpdateId): ?int
    {
        return $telegramUpdateId ? (int) $this->startedAt($telegramUpdateId)->diffInSeconds(now()) : null;
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
     * Card assembly turns a finished search into the card the operator gets,
     * so the search deadline does not cut it off; its own attempt cap keeps
     * it from running on. What does bound it is the queue job running the
     * search: every job that stages a draft allows at least 2040s and the
     * search deadline is at most 1800s, so assembly may run this long past
     * the deadline and still leave the draft save and the Telegram reply
     * inside the job. These numbers move together (see ContinueDraftGallerySearch).
     */
    public const ASSEMBLY_OVERRUN_SECONDS = 180;

    public function remainingAssemblySeconds(?int $telegramUpdateId): ?int
    {
        if (! $telegramUpdateId) {
            return null;
        }

        $elapsed = $this->startedAt($telegramUpdateId)->diffInSeconds(now());

        return max(0, (int) floor($this->settings->searchMaxSeconds() + self::ASSEMBLY_OVERRUN_SECONDS - $elapsed));
    }

    public function assemblyCanStart(?int $telegramUpdateId, int $minimumSeconds = 20): bool
    {
        $remaining = $this->remainingAssemblySeconds($telegramUpdateId);

        return $remaining === null || $remaining >= max(1, $minimumSeconds);
    }

    public function assemblyTimeoutFor(?int $telegramUpdateId, int $configuredSeconds): int
    {
        $configuredSeconds = max(1, $configuredSeconds);
        $remaining = $this->remainingAssemblySeconds($telegramUpdateId);

        return $remaining === null ? $configuredSeconds : max(1, min($configuredSeconds, $remaining));
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
     * Re-delivery or a new bounded continuation job can restart this clock.
     * Automatic continuation requires measurable money remaining, keeps
     * the original update id, and never resets the monetary ledger.
     */
    public function restartSession(?int $telegramUpdateId): void
    {
        if (! $telegramUpdateId) {
            return;
        }

        unset($this->startedAt[$telegramUpdateId]);
        $startedAt = now()->toIso8601String();
        $this->persistStartedAt($telegramUpdateId, $startedAt, restart: true);
        Cache::put(self::RESTART_KEY.$telegramUpdateId, $startedAt, now()->addDay());
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

        $storedStart = $this->persistStartedAt($telegramUpdateId);

        return $this->startedAt[$telegramUpdateId] = CarbonImmutable::parse($storedStart);
    }

    private function persistStartedAt(int $telegramUpdateId, ?string $restartAt = null, bool $restart = false): string
    {
        return DB::transaction(function () use ($telegramUpdateId, $restartAt, $restart): string {
            $update = TelegramUpdate::query()->lockForUpdate()->find($telegramUpdateId);
            $payload = is_array($update?->payload) ? $update->payload : [];
            $storedStart = $payload[self::START_PAYLOAD_KEY] ?? null;

            if (! $restart && is_string($storedStart)) {
                return $storedStart;
            }

            $startedAt = $restart ? $restartAt : AiRun::query()
                ->where('telegram_update_id', $telegramUpdateId)
                // OCR now has its own usage record, but precedes the search.
                // Waiting in the assistant queue must not spend search time.
                ->where(fn ($query) => $query->whereNull('activity->search_budget')
                    ->orWhere('activity->search_budget', true))
                ->whereNotNull('started_at')
                ->oldest('started_at')
                ->value('started_at');

            $startedAt = CarbonImmutable::parse($startedAt ?? now())->toIso8601String();

            // A continuation callback need not make any AI call. Persist its
            // first working instant independently of AiRun and cache lifetime,
            // so another job (or a later first AI call) cannot reset the clock.
            if ($update) {
                $payload[self::START_PAYLOAD_KEY] = $startedAt;
                $update->update(['payload' => $payload]);
            }

            return $startedAt;
        });
    }
}
