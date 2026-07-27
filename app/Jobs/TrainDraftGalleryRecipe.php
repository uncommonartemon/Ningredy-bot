<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class TrainDraftGalleryRecipe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 480;

    public function __construct(
        public int $draftId,
        public int $telegramUpdateId,
        public string $chatId,
    ) {
        $this->onQueue('media');
    }

    public function handle(
        ProductGalleryRecipeTrainer $trainer,
        TelegramClient $telegram,
    ): void {
        $draft = ProductDraft::query()->with('media')->findOrFail($this->draftId);
        throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');
        $url = trim((string) $draft->primary_source_url);
        throw_if($url === '', RuntimeException::class, 'У черновика нет ссылки на товарную карточку.');

        $progress = new TelegramProgressReporter($telegram, $this->chatId);
        $progress->step('1/2 · AI изучает DOM и проверяет новый Playwright-рецепт', 300);
        $images = $trainer->train(
            $url,
            'manual_telegram',
            function (string $level, string $message) use ($progress): void {
                if ($level === 'error') {
                    $progress->failed('AI-тренер', $message);
                } elseif ($level === 'warning') {
                    $progress->warning($message);
                } elseif ($level === 'done') {
                    $progress->done($message);
                } else {
                    $progress->info($message);
                }
            },
            true,
        );
        throw_if(count($images) < 2, RuntimeException::class, 'Новый рецепт не прошёл проверку; старый рецепт и фото сохранены.');

        $draft->update(['image_urls' => $images]);
        $actions = $draft->media->values()->map(fn ($media, int $index): array => [
            'action' => 'replace',
            'media_id' => $media->id,
            'position' => $index + 1,
        ])->all();

        if ($actions === []) {
            $telegram->sendMessage($this->chatId, '✅ Рецепт переобучен. В черновике пока нет фото для замены.');
            Cache::forget("draft-gallery-retrain:{$this->draftId}:queued");

            return;
        }

        $progress->step('2/2 · рецепт готов; заменяю фотографии по новой галерее', 300);
        ProcessDraftPhotoActions::dispatch(
            $draft->id,
            $actions,
            $this->telegramUpdateId,
            $this->chatId,
        );
        Cache::forget("draft-gallery-retrain:{$this->draftId}:queued");
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-gallery-retrain:{$this->draftId}:queued");

        try {
            app(TelegramClient::class)->sendMessage(
                $this->chatId,
                '⚠️ Переобучение источника не завершено. Старый рецепт сохранён.'
                .($exception?->getMessage() ? "\nПричина: ".mb_substr($exception->getMessage(), 0, 500) : ''),
            );
        } catch (Throwable $notificationException) {
            report($notificationException);
        }
    }
}
