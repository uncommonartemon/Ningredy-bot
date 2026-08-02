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

class TopUpDraftGalleryPhotos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

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
            $draft = ProductDraft::query()->with('media')->findOrFail($this->draftId);
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');

            $progress = new TelegramProgressReporter($telegram, $this->chatId);
            $progress->step('Ищу дополнительные фото', 780);
            $stored = $images->topUpDraftMedia($draft, fn (string $message) => $progress->info($message));

            if ($stored > 0) {
                $progress->done("Добавлено новых фото: {$stored}");
                $presenter->sendReview($telegram, $this->chatId, $draft->fresh('media'));
            } else {
                $progress->done('Поиск завершён: новых непохожих фото не нашлось.');
            }
        } finally {
            Cache::forget("draft-gallery-topup:{$this->draftId}:queued");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-gallery-topup:{$this->draftId}:queued");

        try {
            app(TelegramClient::class)->sendMessage(
                $this->chatId,
                '⚠️ Не удалось найти дополнительные фото.'
                .($exception?->getMessage() ? "\nПричина: ".mb_substr($exception->getMessage(), 0, 500) : ''),
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
