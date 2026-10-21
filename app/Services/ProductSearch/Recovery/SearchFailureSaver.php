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
use Throwable;

/**
 * Stands between a failed request and the bare error message. When the request
 * already saved a draft, the operator gets that draft instead: resumed once from
 * where it stopped while money remains, otherwise shown as it is with its usual
 * buttons. Deliberately no AI call here - the failure may be the AI provider.
 */
final class SearchFailureSaver
{
    /** Failures a fresh attempt can get past; a broken key or empty balance cannot. */
    private const RESUMABLE_KINDS = ['rate_limit', 'timeout', 'connection', 'unknown'];

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
        try {
            $draft = $update->chat_id ? ProductDraft::query()->where('telegram_update_id', $update->id)
                ->where('status', 'pending_review')->latest('id')->first() : null;
            if ($draft === null || app(ProductSearchCancellation::class)->isCancelled($update->id)) {
                return false;
            }
            $chatId = (string) $update->chat_id;
            $photos = $draft->media()->count();
            $code = $runId ? " (код журнала AI-{$runId})" : '';
            $resumable = in_array($presented['kind'] ?? 'unknown', self::RESUMABLE_KINDS, true);
            $alreadyResumed = ProductSourceAttempt::query()->where('product_draft_id', $draft->id)
                ->where('phase', 'delivery_guard')->where('action', 'resume_after_failure')->exists();
            $moneyLeft = $this->settings->maxSearchCostUsd() > 0 && ! $this->costBudget->unmeasurable($update->id)
                && $this->costBudget->hasRemaining($update->id);
            // A draft whose search finished and whose card is assembled has nothing
            // left to resume (live #588 searched its complete card again): show it.
            $finished = $draft->images_staged_at !== null && ! $draft->reconciliationPending();

            if ($resumable && ! $finished && ! $alreadyResumed && $moneyLeft
                && Cache::add("draft-gallery-continue:{$draft->id}:queued", true, 2100)) {
                ContinueDraftGallerySearch::dispatch($draft->id, $chatId, $update->id, $draft->telegram_update_id);
                $this->record($draft, $update, 'resume_after_failure', $presented, $photos);
                app(TelegramClient::class)->sendMessage($chatId, "⚠️ Поиск прервался сбоем{$code}. Найденное сохранено: фото — {$photos}."
                    .' Продолжаю с места остановки в том же бюджете.');

                return true;
            }

            $this->record($draft, $update, 'deliver_saved_after_failure', $presented, $photos);
            app(TelegramClient::class)->sendMessage($chatId, '⚠️ '.($resumable ? "Поиск прервался сбоем{$code}." : (string) ($presented['message'] ?? 'Поиск прервался сбоем.'))
                ."\nНайденное сохранено — отправляю черновик: его можно принять, продолжить поиск или исправить.");
            $this->presenter->sendReview(app(TelegramClient::class), $chatId, $draft->fresh('media'));

            return true;
        } catch (Throwable $exception) {
            // The saver must never be the reason the operator hears nothing.
            report($exception);

            return false;
        }
    }

    private function record(ProductDraft $draft, TelegramUpdate $update, string $action, array $presented, int $photos): void
    {
        $this->attempts->record([
            'telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'product_url' => $draft->primary_source_url ?? '', 'actor' => 'server',
            'phase' => 'delivery_guard', 'action' => $action, 'status' => 'completed',
            'decision' => $presented['kind'] ?? 'unknown',
            'message' => $action === 'resume_after_failure'
                ? 'Запрос упал после сохранения черновика; поиск продолжен с места остановки.'
                : 'Запрос упал после сохранения черновика; оператор получил сохранённое вместо голой ошибки.',
            'output' => ['saved_photos' => $photos, 'error' => mb_substr((string) ($presented['message'] ?? ''), 0, 500)],
        ]);
    }
}
