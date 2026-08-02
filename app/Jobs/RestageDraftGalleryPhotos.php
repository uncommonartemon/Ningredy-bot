<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * "Photos don't fit" redo for a still-pending draft: re-runs the full
 * gallery search instead of replacing one photo at a time. The current
 * page/CDN sources, direct URLs and perceptual hashes are persisted on the
 * draft blacklist first, so rejected photos cannot return under another
 * size or URL. A failed search still leaves the previous gallery in place.
 */
class RestageDraftGalleryPhotos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1380;

    public function __construct(
        public int $draftId,
        public string $chatId,
    ) {
        $this->onQueue('media');
    }

    public function handle(
        ProductImageStorage $images,
        DraftTelegramPresenter $presenter,
        TelegramClient $telegram,
    ): void {
        try {
            $draft = ProductDraft::query()->findOrFail($this->draftId);
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');

            $progress = new TelegramProgressReporter($telegram, $this->chatId);
            $images->excludeCurrentDraftGallery($draft);
            $progress->step('Ищу другие фото без прежних источников и дублей', 1080);
            $stored = $images->stage($draft, fn (string $message) => $progress->info($message));

            if ($stored > 0) {
                $progress->done("Галерея обновлена, фото: {$stored}");
            } else {
                $progress->done('Поиск завершён: других фото не нашлось, прежняя галерея сохранена.');
            }

            $presenter->sendReview($telegram, $this->chatId, $draft->fresh('media'));
        } finally {
            Cache::forget("draft-gallery-restage:{$this->draftId}:queued");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-gallery-restage:{$this->draftId}:queued");

        try {
            app(TelegramClient::class)->sendMessage(
                $this->chatId,
                '⚠️ Не удалось найти фото заново. Прежняя галерея сохранена.'
                .($exception?->getMessage() ? "\nПричина: ".mb_substr($exception->getMessage(), 0, 500) : ''),
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
