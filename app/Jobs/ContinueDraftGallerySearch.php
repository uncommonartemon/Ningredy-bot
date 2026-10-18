<?php

namespace App\Jobs;

use App\Exceptions\ProductSearchCancelled;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramAssistantReplyDelivery;
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
    // PROJECT_STRATEGY.md requires these three numbers to move together.
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
        $cycleStarted = false;
        $terminalStatus = 'failed';
        $cancellation = app(ProductSearchCancellation::class);

        try {
            $cancellation->throwIfCancelled($this->telegramUpdateId);
            $draft = ProductDraft::query()->with('telegramUpdate')->findOrFail($this->draftId);
            throw_unless(
                $this->expectedDraftTelegramUpdateId !== null
                    && (int) $draft->telegram_update_id === $this->expectedDraftTelegramUpdateId,
                RuntimeException::class,
                'Queued gallery search belongs to a different draft generation.',
            );
            throw_unless($draft->status === 'pending_review', RuntimeException::class, 'Черновик уже обработан.');

            $delivery = app(TelegramAssistantReplyDelivery::class);
            if ($checkpoint = $delivery->draftFor($this->telegramUpdateId, $draft->id, $this->expectedDraftTelegramUpdateId)) {
                $delivery->deliver($checkpoint, $telegram, presenter: $presenter);

                return;
            }

            // The webhook has already acknowledged the callback. Expose its
            // ongoing work to /reset until this whole automatic chain ends.
            TelegramUpdate::query()->findOrFail($this->telegramUpdateId);
            $cycleStarted = TelegramUpdate::query()->whereKey($this->telegramUpdateId)
                ->whereNull('cancel_requested_at')->where('status', '!=', 'cancelled')
                ->update(['status' => 'processing', 'processed_at' => null]) > 0;
            if (! $cycleStarted) {
                throw new ProductSearchCancelled;
            }

            // A queue job owns a bounded time slice, never a fresh money budget.
            $timeBudget = app(ProductSearchTimeBudget::class);
            if (! $timeBudget->canStart($this->telegramUpdateId, 120)
                && app(ProductSearchCostBudget::class)->hasRemaining($this->telegramUpdateId)) {
                $timeBudget->restartSession($this->telegramUpdateId);
            }

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
            $reconciliationOnly = $draft->gallery_confirmed_sufficient && $draft->reconciliationPending();
            $progress->step($reconciliationOnly ? 'Повторяю сверку карточки с источником фото' : 'Продолжаю Playwright-поиск с места остановки', 1680);
            $stored = $images->continueStage(
                $draft,
                fn (string $message) => $cancellation->isCancelled($this->telegramUpdateId) ? null : $progress->info($message),
                $this->telegramUpdateId,
            );
            $cancellation->throwIfCancelled($this->telegramUpdateId);
            $fresh = $draft->fresh('media');
            if ($fresh->gallery_search_stop_reason === 'exhausted'
                && app(ProductSearchCostBudget::class)->exceeded($this->telegramUpdateId)) {
                $fresh->update(['gallery_search_stop_reason' => 'cost_budget']);
            }

            // 'exhausted' means this cycle's sources came up short of the
            // category's minimum but the request's own time/cost budget
            // (shared across cycles via the same telegram_update_id) isn't
            // actually spent - keep chaining automatically instead of
            // leaving the user to press "продолжить" again for every round.
            // Ending a time slice renews only the job clock when money remains.
            // The original monetary ledger stays shared across every job.
            // Card assembly already recovered in stage(), before delivery.
            // Do not multiply its bounded retries through another queue chain.
            if ($fresh->gallery_status !== 'complete'
                && (($fresh->gallery_search_stop_reason === 'exhausted'
                        && ! app(ProductSearchCostBudget::class)->exceeded($this->telegramUpdateId))
                    || ($fresh->gallery_search_stop_reason === 'time_budget'
                        && app(ProductSearchCostBudget::class)->hasRemaining($this->telegramUpdateId)))) {
                Cache::put(
                    "draft-gallery-continue:{$this->draftId}:queued",
                    true,
                    $this->timeout + 60,
                );
                $progress->info('Поиск продолжается автоматически; денежный бюджет и сохранённые результаты остаются общими.');
                $autoChainDispatched = true;
                self::dispatch($this->draftId, $this->chatId, $this->telegramUpdateId, $this->expectedDraftTelegramUpdateId)
                    ->afterCommit();

                return;
            }

            if ($fresh->reconciliationPending()) {
                $previewCount = count($fresh->verifiedPhotoPaths());
                if ($previewCount > 0) {
                    $progress->warning("Частичный результат: фото — {$previewCount}. Отправляю найденное; характеристики ещё не согласованы.");
                } else {
                    $progress->failed('Сверка не завершена', 'Пригодных фото пока нет. Черновик сохранён для продолжения.');
                }
            } elseif ($reconciliationOnly) {
                $progress->done("Карточка сверена с источником; сохранены фото: {$fresh->media->count()}");
            } elseif ($stored > 0) {
                $progress->done("Продолжение завершено: галерея обновлена, фото: {$fresh->media->count()}");
            } else {
                $progress->done("Продолжение завершено: новых фото нет, сохранены прежние {$previousCount}");
            }

            $checkpoint = $delivery->prepareDraft(TelegramUpdate::findOrFail($this->telegramUpdateId), $fresh);
            $delivery->deliver($checkpoint, $telegram, presenter: $presenter);
            $terminalStatus = 'completed';
        } catch (ProductSearchCancelled) {
            // Cancellation is final for this run, not a failed job to retry
            // and not permission to send the obsolete draft again.
            $terminalStatus = 'cancelled';

            return;
        } finally {
            if (! $autoChainDispatched) {
                Cache::forget("draft-gallery-continue:{$this->draftId}:queued");
                if ($cycleStarted && ! (($checkpoint ?? null) && in_array($checkpoint->fresh()->status, ['pending', 'running', 'failed'], true))) {
                    $updates = TelegramUpdate::query()->whereKey($this->telegramUpdateId);
                    if ($terminalStatus !== 'cancelled') {
                        $updates->whereNull('cancel_requested_at')->where('status', '!=', 'cancelled');
                    }
                    $updates->update(['status' => $terminalStatus, 'processed_at' => now()]);
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget("draft-gallery-continue:{$this->draftId}:queued");

        if ($checkpoint = app(TelegramAssistantReplyDelivery::class)->draftFor($this->telegramUpdateId, $this->draftId, $this->expectedDraftTelegramUpdateId)) {
            if (! in_array($checkpoint->status, ['completed', 'cancelled'], true)) {
                DeliverTelegramAssistantReply::dispatch($checkpoint->id)->delay(now()->addSeconds(30));
            }

            return;
        }

        if ($exception instanceof ProductSearchCancelled
            || app(ProductSearchCancellation::class)->isCancelled($this->telegramUpdateId)) {
            return;
        }

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
