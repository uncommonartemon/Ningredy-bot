<?php

namespace App\Services\Telegram;

use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TopUpDraftGalleryPhotos;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\Cache;

/**
 * Looking for photos of a pending draft: a search stopped by a limit or with nothing found
 * goes on with a new budget, a finished one is topped up and keeps what it has, or the
 * gallery is searched again without its old sources. The card's buttons and the operator's
 * words start the same work (live 2026-11-06: «мало фото, найди ещё» wrote a characteristic,
 * because the assistant could do none of this).
 */
class DraftPhotoSearch
{
    public const string STARTED = 'started';

    public const string ALREADY_RUNNING = 'already_running';

    public const string NOT_STOPPED = 'not_stopped';

    /** Telegram's own update ids stay far below this; a row above it is the operator's words acting as a button. */
    private const int WORDS_AS_BUTTON = 9_000_000_000_000;

    private const array STOPPED = ['cost_budget', 'time_budget', 'exhausted', 'specifications_unreconciled', 'stalled'];

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DraftTelegramPresenter $presenter,
    ) {}

    public function wasStopped(ProductDraft $draft): bool
    {
        return in_array($draft->gallery_search_stop_reason, self::STOPPED, true) || $draft->images_staged_at === null;
    }

    public function continue(ProductDraft $draft, string $chatId, int $updateId): string
    {
        if (! $this->wasStopped($draft)) {
            return self::NOT_STOPPED;
        }
        if (! Cache::add("draft-gallery-continue:{$draft->id}:queued", true, now()->addMinutes(35))) {
            return self::ALREADY_RUNNING;
        }
        $this->presenter->clearForSearchContinuation($this->telegram, $draft);
        ContinueDraftGallerySearch::dispatch($draft->id, $chatId, $updateId, $draft->telegram_update_id);

        return self::STARTED;
    }

    public function topUp(ProductDraft $draft, string $chatId, int $updateId): string
    {
        if (! Cache::add("draft-gallery-topup:{$draft->id}:queued", true, now()->addMinutes(16))) {
            return self::ALREADY_RUNNING;
        }
        TopUpDraftGalleryPhotos::dispatch($draft->id, $chatId, $updateId, $draft->telegram_update_id);

        return self::STARTED;
    }

    /** Photos from the page the operator named: their word that it is the right one. */
    public function fromPage(ProductDraft $draft, string $chatId, int $updateId, string $page): string
    {
        if (! Cache::add("draft-gallery-topup:{$draft->id}:queued", true, now()->addMinutes(16))) {
            return self::ALREADY_RUNNING;
        }
        TopUpDraftGalleryPhotos::dispatch($draft->id, $chatId, $updateId, $draft->telegram_update_id, $page);

        return self::STARTED;
    }

    public function other(ProductDraft $draft, string $chatId, int $updateId): string
    {
        if (! Cache::add("draft-gallery-restage:{$draft->id}:queued", true, now()->addMinutes(35))) {
            return self::ALREADY_RUNNING;
        }
        RestageDraftGalleryPhotos::dispatch($draft->id, $chatId, $updateId, $draft->telegram_update_id);

        return self::STARTED;
    }

    /**
     * A search started from the chat runs on a request row of its own, as a button press
     * does: the jobs take their row over and close it when they end, which must not be
     * the assistant's request that is finishing its answer at the same moment.
     */
    public function pressFor(TelegramUpdate $words, string $button): TelegramUpdate
    {
        return TelegramUpdate::query()->firstOrCreate(['update_id' => self::WORDS_AS_BUTTON + $words->id], [
            'telegram_user_id' => $words->telegram_user_id,
            'chat_id' => $words->chat_id,
            'message_id' => $words->message_id,
            'username' => $words->username,
            'text' => $button,
            'payload' => ['callback_query' => ['id' => 'words-'.$words->id, 'data' => $button,
                'from' => ['id' => (int) $words->telegram_user_id],
                'message' => ['message_id' => $words->message_id, 'chat' => ['id' => (int) $words->chat_id]]]],
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }
}
