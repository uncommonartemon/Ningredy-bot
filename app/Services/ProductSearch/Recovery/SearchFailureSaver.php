<?php

namespace App\Services\ProductSearch\Recovery;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductSourceAttemptRecorder;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stands between a failed request and the bare error message. When the request
 * already saved a draft, the operator gets that draft instead: resumed once from
 * where it stopped while money remains, otherwise shown as it is with its usual
 * buttons. A worker that stopped mid-search is the same case: the request goes
 * on from its draft, never searched again from nothing. Deliberately no AI call
 * here - the failure may be the AI provider.
 */
final class SearchFailureSaver
{
    /** Failures a fresh attempt can get past; a broken key or empty balance cannot. */
    private const RESUMABLE_KINDS = ['rate_limit', 'timeout', 'connection', 'unknown', 'worker_stopped'];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly ProductSearchCostBudget $costBudget,
        private readonly ProductSourceAttemptRecorder $attempts,
        private readonly DraftTelegramPresenter $presenter,
    ) {}

    /**
     * @param  array{kind?: string, retryable?: bool, message?: string}  $presented  the error as AiErrorPresenter describes it
     * @return bool true when the operator was answered with the saved draft and the bare error must not be sent
     */
    public function rescue(TelegramUpdate $update, array $presented, ?int $runId = null): bool
    {
        return $this->save($update, $presented, $runId) !== null;
    }

    /**
     * @param  array{kind?: string, retryable?: bool, message?: string}  $presented  the error as AiErrorPresenter describes it,
     *                                                                               or kind worker_stopped for a process that is gone
     * @return 'resumed'|'delivered'|null resumed: a continuation of the saved draft now owns the request;
     *                                    delivered: the operator has the saved draft; null: nothing was saved
     */
    public function save(TelegramUpdate $update, array $presented, ?int $runId = null): ?string
    {
        try {
            $draft = $update->chat_id ? ProductDraft::query()->where('telegram_update_id', $update->id)
                ->where('status', 'pending_review')->latest('id')->first() : null;
            if ($draft === null || app(ProductSearchCancellation::class)->isCancelled($update->id)) {
                return null;
            }
            if ($this->resume($update, $draft, $presented, $runId)) {
                return 'resumed';
            }

            $chatId = (string) $update->chat_id;
            $resumable = in_array($presented['kind'] ?? 'unknown', self::RESUMABLE_KINDS, true);
            $this->record($draft, $update, 'deliver_saved_after_failure', $presented, $draft->media()->count());
            app(TelegramClient::class)->sendMessage($chatId, '⚠️ '.($resumable ? $this->cause($presented, $runId).'.' : (string) ($presented['message'] ?? 'Поиск прервался сбоем.'))
                ."\nНайденное сохранено — отправляю черновик: его можно принять, продолжить поиск или исправить.");
            $this->presenter->sendReview(app(TelegramClient::class), $chatId, $draft->fresh('media'));

            return 'delivered';
        } catch (Throwable $exception) {
            // The saver must never be the reason the operator hears nothing.
            report($exception);

            return null;
        }
    }

    /**
     * Continues the saved draft from where it stopped - its photos, finished
     * pages and the watcher's verdicts stay - once per draft, while the failure
     * is one a fresh attempt can get past, money remains and its search is not
     * finished. True when a continuation now owns the request.
     */
    public function resume(TelegramUpdate $update, ProductDraft $draft, array $presented, ?int $runId = null): bool
    {
        $alreadyResumed = ProductSourceAttempt::query()->where('product_draft_id', $draft->id)
            ->where('phase', 'delivery_guard')->where('action', 'resume_after_failure')->exists();
        $moneyLeft = $this->settings->maxSearchCostUsd() > 0 && ! $this->costBudget->unmeasurable($update->id)
            && $this->costBudget->hasRemaining($update->id);
        // A draft whose search finished and whose card is assembled has nothing
        // left to resume (live #588 searched its complete card again): show it.
        $finished = $draft->images_staged_at !== null && ! $draft->reconciliationPending();
        if (! in_array($presented['kind'] ?? 'unknown', self::RESUMABLE_KINDS, true) || $finished || $alreadyResumed || ! $moneyLeft
            || ! $update->chat_id || app(ProductSearchCancellation::class)->isCancelled($update->id)) {
            return false;
        }
        // A continuation already queued for this draft owns it; a second one would race it.
        $queued = "draft-gallery-continue:{$draft->id}:queued";
        if (! Cache::add($queued, true, 2100) && $this->continuationQueued($draft)) {
            return true;
        }
        Cache::put($queued, true, 2100);
        $chatId = (string) $update->chat_id;
        $photos = $draft->media()->count();
        ContinueDraftGallerySearch::dispatch($draft->id, $chatId, $update->id, $draft->telegram_update_id);
        $this->record($draft, $update, 'resume_after_failure', $presented, $photos);
        app(TelegramClient::class)->sendMessage($chatId, '⚠️ '.$this->cause($presented, $runId).". Найденное сохранено: фото — {$photos}."
            .' Продолжаю с места остановки в том же бюджете.');

        return true;
    }

    private function cause(array $presented, ?int $runId): string
    {
        return ($presented['kind'] ?? null) === 'worker_stopped'
            ? 'Обработчик остановился во время поиска'
            : 'Поиск прервался сбоем'.($runId ? " (код журнала AI-{$runId})" : '');
    }

    /** Read from the serialized command, never unserialized: written by another process. */
    private function continuationQueued(ProductDraft $draft): bool
    {
        return DB::table('jobs')->where('payload', 'like', '%ContinueDraftGallerySearch%')->pluck('payload')
            ->contains(fn (string $payload): bool => str_contains((string) data_get(json_decode($payload, true), 'data.command'),
                '"draftId";i:'.$draft->id.';'));
    }

    private function record(ProductDraft $draft, TelegramUpdate $update, string $action, array $presented, int $photos): void
    {
        $this->attempts->record([
            'telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'product_url' => $draft->primary_source_url ?? '', 'actor' => 'server',
            'phase' => 'delivery_guard', 'action' => $action, 'status' => 'completed',
            'decision' => $presented['kind'] ?? 'unknown',
            'message' => ($presented['kind'] ?? null) === 'worker_stopped'
                ? ($action === 'resume_after_failure'
                    ? 'Обработчик остановился после сохранения черновика; поиск продолжен с места остановки.'
                    : 'Обработчик остановился после сохранения черновика; оператор получил сохранённое.')
                : ($action === 'resume_after_failure'
                    ? 'Запрос упал после сохранения черновика; поиск продолжен с места остановки.'
                    : 'Запрос упал после сохранения черновика; оператор получил сохранённое вместо голой ошибки.'),
            'output' => ['saved_photos' => $photos, 'error' => mb_substr((string) ($presented['message'] ?? ''), 0, 500)],
        ]);
    }
}
