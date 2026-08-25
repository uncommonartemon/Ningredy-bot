<?php

namespace App\Jobs;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProgressReporter;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ContinueDraftGallerySearch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Must exceed the internal search deadline (AiSettings::searchMaxSeconds(),
    // max 1800s) with room for the post-search reserve (save draft, cleanup,
    // reply to Telegram) and stay under the queue worker's --timeout (2160s).
    // PROJECT_LOGIC.md requires these three numbers to move together.
    public int $timeout = 2040;

    public int $uniqueFor = 2100;

    public function __construct(
        public int $draftId,
        public string $chatId,
        public int $telegramUpdateId,
        public ?int $expectedDraftTelegramUpdateId = null,
    ) {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->draftId.':'.($this->expectedDraftTelegramUpdateId ?? 'missing-generation');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Belt-and-suspenders alongside the controller's Cache::add() guard:
        // that flag's TTL can expire while this job is still legitimately
        // running, letting a second button press dispatch a genuine
        // duplicate against the same draft. Shares its lock key with
        // RestageDraftGalleryPhotos/TopUpDraftGalleryPhotos so none of the
        // three gallery-mutating jobs can race each other either.
        return [(new WithoutOverlapping("draft-gallery-search:{$this->draftId}"))
            ->releaseAfter($this->timeout + 60)
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(
        ProductImageStorage $images,
        DraftTelegramPresenter $presenter,
        TelegramClient $telegram,
    ): void {
        $autoChainDispatched = false;

        try {
            $draft = ProductDraft::query()->with('telegramUpdate')->findOrFail($this->draftId);
            throw_unless(
                $this->expectedDraftTelegramUpdateId !== null
                    && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId,
                RuntimeException::class,
                'Queued gallery search belongs to a different draft generation.',
            );
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');

            $existingProgressMessageId = (int) data_get(
                $draft->telegramUpdate?->payload,
                '_progress_message_id',
                0,
            );
            $progress = new TelegramProgressReporter(
                $telegram,
                $this->chatId,
                true,
                $existingProgressMessageId ?: null,
                function (int $messageId): void {
                    $update = TelegramUpdate::query()->find($this->telegramUpdateId);

                    if ($update) {
                        $payload = is_array($update->payload) ? $update->payload : [];
                        $payload['_progress_message_id'] = $messageId;
                        $update->update(['payload' => $payload]);
                    }
                },
            );
            $previousCount = $draft->media()->count();
            $progress->step('Продолжаю Playwright-поиск с места остановки', 1680);
            $stored = $images->continueStage(
                $draft,
                fn (string $message) => $progress->info($message),
                $this->telegramUpdateId,
            );
            $fresh = $draft->fresh('media');

            // 'exhausted' means this cycle's sources came up short of the
            // category's minimum but the request's own time/cost budget
            // (shared across cycles via the same telegram_update_id) isn't
            // actually spent - keep chaining automatically instead of
            // leaving the user to press "продолжить" again for every round.
            // cost_budget/time_budget means that allowance really is gone,
            // so this is where the chain has to stop and report back.
            if ($fresh->gallery_status !== 'complete' && $fresh->gallery_search_stop_reason === 'exhausted') {
                Cache::put(
                    "draft-gallery-continue:{$this->draftId}:queued",
                    true,
                    $this->timeout + 60,
                );
                $progress->info('Все источники этого цикла проверены без результата; автоматически продолжаю новым циклом.');
                $autoChainDispatched = true;
                self::dispatch($this->draftId, $this->chatId, $this->telegramUpdateId, $this->expectedDraftTelegramUpdateId)
                    ->afterCommit();

                return;
            }

            if ($stored > 0) {
                $progress->done("Продолжение завершено: галерея обновлена, фото: {$fresh->media->count()}");
            } else {
                $progress->done("Продолжение завершено: новых фото нет, сохранены прежние {$previousCount}");
            }

            $presenter->sendReview($telegram, $this->chatId, $fresh);
        } finally {
            if (! $autoChainDispatched) {
                Cache::forget("draft-gallery-continue:{$this->draftId}:queued");
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-gallery-continue:{$this->draftId}:queued");

        try {
            $draft = ProductDraft::query()->find($this->draftId);

            if ($draft
                && $this->expectedDraftTelegramUpdateId !== null
                && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId
                && $draft->status === 'pending_review') {
                app(DraftTelegramPresenter::class)->sendReview(
                    app(TelegramClient::class),
                    $this->chatId,
                    $draft->fresh('media'),
                );
            }
        } catch (Throwable $notificationException) {
            report($notificationException);
        }

        if ($exception) {
            report($exception);
        }
    }
}
