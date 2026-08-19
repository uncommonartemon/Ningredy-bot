<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    // stage() runs the same search-budget loop as a fresh search (up to
    // AiSettings::searchMaxSeconds(), max 1800s). The job's own hard timeout
    // must stay above that ceiling plus overhead, and below the queue
    // worker's --timeout (2160s, see run-queue-worker.bat / the systemd
    // unit) - PROJECT_LOGIC.md requires all three numbers to move together.
    public int $timeout = 2040;

    public function __construct(
        public int $draftId,
        public string $chatId,
        public int $telegramUpdateId,
    ) {
        $this->onQueue('media');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Belt-and-suspenders alongside the controller's Cache::add() guard:
        // that flag's TTL (see TelegramWebhookController) can expire while
        // this job is still legitimately running, letting a second button
        // press dispatch a genuine duplicate against the same draft. This
        // queue-level lock is the actual concurrency guarantee.
        return [(new WithoutOverlapping("draft-gallery-search:{$this->draftId}"))->releaseAfter($this->timeout + 60)];
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
            $stored = $images->stage($draft, fn (string $message) => $progress->info($message), $this->telegramUpdateId);

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
