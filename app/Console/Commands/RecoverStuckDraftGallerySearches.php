<?php

namespace App\Console\Commands;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\ProductDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverStuckDraftGallerySearches extends Command
{
    protected $signature = 'gallery:recover-stuck-drafts';

    protected $description = 'Resume drafts whose gallery search never finished a pass - the owning job was killed '
        .'by its own timeout mid-search (e.g. a hung network call), leaving images_staged_at unset forever otherwise.';

    // Comfortably above two full sequential ProcessTelegramMessage attempts
    // (2 x 2100s timeout, plus the WithoutOverlapping lock/backoff gap
    // between them) so this never races a retry that is still legitimately
    // running its own stage() call on the same draft.
    private const STUCK_AFTER_MINUTES = 75;

    private const MAX_RECOVERY_ATTEMPTS = 3;

    /**
     * Recovery is for a search the operator is still waiting on, not for the
     * archaeology of every draft that ever stalled. Without a ceiling this
     * picked up drafts from previous days - one surfaced in the middle of an
     * unrelated live search and read, reasonably enough, as the bot answering
     * yesterday's question. Anything older than this is the operator's to
     * resume by hand from the draft's own "continue search" button.
     */
    private const ABANDON_AFTER_HOURS = 6;

    public function handle(): int
    {
        ProductDraft::query()
            ->where('status', 'pending_review')
            ->whereNull('images_staged_at')
            ->where('created_at', '<=', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->where('created_at', '>', now()->subHours(self::ABANDON_AFTER_HOURS))
            ->with('telegramUpdate')
            ->each(function (ProductDraft $draft): void {
                $chatId = $draft->telegramUpdate?->chat_id;

                if (! $chatId || ! $draft->telegram_update_id) {
                    return;
                }

                $attemptsKey = "draft-gallery-recovery-attempts:{$draft->id}";
                $attempts = (int) Cache::get($attemptsKey, 0);

                if ($attempts >= self::MAX_RECOVERY_ATTEMPTS) {
                    return;
                }

                $queuedKey = "draft-gallery-continue:{$draft->id}:queued";

                if (! Cache::add($queuedKey, true, now()->addMinutes(35))) {
                    return;
                }

                Cache::put($attemptsKey, $attempts + 1, now()->addHours(6));

                ContinueDraftGallerySearch::dispatch(
                    $draft->id,
                    (string) $chatId,
                    $draft->telegram_update_id,
                    $draft->telegram_update_id,
                );

                $this->info("Dispatched recovery for draft #{$draft->id} (attempt ".($attempts + 1).').');
            });

        return self::SUCCESS;
    }
}
