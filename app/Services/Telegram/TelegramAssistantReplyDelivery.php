<?php

namespace App\Services\Telegram;

use App\Exceptions\ProductSearchCancelled;
use App\Jobs\DeliverTelegramAssistantReply;
use App\Models\AiOperation;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Persist final delivery separately from paid work; receipts resume individual messages. */
class TelegramAssistantReplyDelivery
{
    public function assistantFor(TelegramUpdate $update): ?AiOperation
    {
        return AiOperation::query()->where('telegram_update_id', $update->id)
            ->where('action', 'deliver_assistant_reply')->where('target_type', 'assistant_reply')->latest('id')->first();
    }

    public function prepareAssistant(TelegramUpdate $update, int $runId, array $payload): AiOperation
    {
        return $this->prepare($update, 'assistant_reply', 'assistant-reply:'.$runId, $payload);
    }

    public function draftFor(int $updateId, int $draftId, ?int $generation): ?AiOperation
    {
        return AiOperation::where('idempotency_key', $this->draftKey($updateId, $draftId, $generation))->first();
    }

    public function prepareDraft(TelegramUpdate $update, ProductDraft $draft): AiOperation
    {
        return $this->prepare($update, 'draft_reply', $this->draftKey($update->id, $draft->id, $draft->telegram_update_id), [
            'draft_id' => $draft->id, 'draft_generation' => $draft->telegram_update_id, 'footnote' => '',
        ]);
    }

    private function draftKey(int $updateId, int $draftId, ?int $generation): string
    {
        return 'draft-final-reply:'.$updateId.':'.$draftId.':'.($generation ?? 'missing');
    }

    private function prepare(TelegramUpdate $update, string $type, string $key, array $payload): AiOperation
    {
        return AiOperation::firstOrCreate(['idempotency_key' => $key], [
            'telegram_update_id' => $update->id, 'telegram_user_id' => $update->telegram_user_id,
            'tool' => self::class, 'action' => 'deliver_assistant_reply', 'target_type' => $type,
            'status' => 'pending', 'payload' => [...$payload, 'chat_id' => (string) $update->chat_id],
        ]);
    }

    public function deliver(AiOperation $checkpoint, TelegramClient $telegram, ?ProductCardPresenter $cards = null, ?DraftTelegramPresenter $presenter = null): void
    {
        Cache::lock('assistant-reply-delivery:'.$checkpoint->id, 120)->block(10, function () use ($checkpoint, $telegram, $cards, $presenter): void {
            $checkpoint->refresh();
            if (in_array($checkpoint->status, ['completed', 'cancelled'], true)) {
                return;
            }
            $update = TelegramUpdate::find($checkpoint->telegram_update_id);
            if (! $update || app(ProductSearchCancellation::class)->isCancelled($update->id)
                || (string) $checkpoint->telegram_user_id !== (string) $update->telegram_user_id
                || (string) data_get($checkpoint->payload, 'chat_id') !== (string) $update->chat_id) {
                $checkpoint->update(['status' => 'cancelled', 'executed_at' => now()]);

                return;
            }
            $checkpoint->update(['status' => 'running', 'error' => null]);
            $claimed = TelegramUpdate::whereKey($update->id)->whereNull('cancel_requested_at')->where('status', '!=', 'cancelled')
                ->update(['status' => 'delivering', 'processed_at' => null]);
            if (! $claimed) {
                $checkpoint->update(['status' => 'cancelled', 'executed_at' => now()]);

                return;
            }
            try {
                $outcome = TelegramWebhookDelivery::within($update->id, fn () => $this->render($checkpoint, $update, $telegram,
                    $cards ?? app(ProductCardPresenter::class), $presenter ?? app(DraftTelegramPresenter::class)), checkCancellation: true);
                if (in_array($update->fresh()->status, ['processing', 'queued'], true)) {
                    $outcome = 'search_continues';
                }
                DB::transaction(function () use ($checkpoint, $update, $outcome): void {
                    app(ProductSearchCancellation::class)->throwIfCancelled($update->id);
                    $checkpoint->update(['status' => 'completed', 'error' => null, 'executed_at' => now(), 'result' => ['delivery_outcome' => $outcome]]);
                    // The presenter may have started another bounded search cycle.
                    TelegramUpdate::whereKey($update->id)->where('status', 'delivering')->whereNull('cancel_requested_at')
                        ->update(['status' => 'completed', 'processed_at' => now(), 'error' => null]);
                });
            } catch (ProductSearchCancelled) {
                $checkpoint->update(['status' => 'cancelled', 'executed_at' => now(), 'result' => ['delivery_outcome' => 'cancelled']]);
                $update->update(['status' => 'cancelled', 'processed_at' => now()]);
            } catch (Throwable $exception) {
                // TelegramClient may wrap a cancellation raised immediately before its HTTP call.
                if (app(ProductSearchCancellation::class)->isCancelled($update->id)) {
                    $checkpoint->update(['status' => 'cancelled', 'executed_at' => now(), 'result' => ['delivery_outcome' => 'cancelled']]);
                    $update->update(['status' => 'cancelled', 'processed_at' => now()]);

                    return;
                }
                $token = (string) config('services.telegram.bot_token');
                $error = $token === '' ? $exception->getMessage() : str_replace($token, '[redacted]', $exception->getMessage());
                $checkpoint->update(['status' => 'failed', 'error' => mb_substr($error, 0, 2000)]);
                TelegramUpdate::whereKey($update->id)->whereNull('cancel_requested_at')->where('status', '!=', 'cancelled')
                    ->update(['status' => 'failed', 'processed_at' => null, 'error' => mb_substr($error, 0, 2000)]);
                DeliverTelegramAssistantReply::dispatch($checkpoint->id)->delay(now()->addSeconds(30));
                throw $exception;
            }
        });
    }

    private function render(AiOperation $checkpoint, TelegramUpdate $update, TelegramClient $telegram, ProductCardPresenter $cards, DraftTelegramPresenter $presenter): string
    {
        $payload = $checkpoint->payload;
        $chat = (string) $update->chat_id;
        $footnote = (string) ($payload['footnote'] ?? '');
        $databaseIds = (array) ($payload['database_operation_ids'] ?? []);
        if ($databaseIds !== []) {
            $sent = false;
            foreach (AiOperation::whereIn('id', $databaseIds)->where('telegram_update_id', $update->id)
                ->where('telegram_user_id', $update->telegram_user_id)->where('action', 'database_changes')
                ->where('status', 'awaiting_confirmation')->orderBy('id')->get() as $operation) {
                if ((int) data_get($operation->payload, 'expires_at', 0) > now()->getTimestamp()) {
                    app(TelegramDatabaseOperator::class)->sendConfirmation($telegram, $chat, $operation, $footnote);
                    $sent = true;
                }
            }

            return $sent ? 'sent' : 'skipped_stale';
        }
        if (! empty($payload['draft_id'])) {
            $draft = ProductDraft::find($payload['draft_id']);
            if ($draft && $draft->status === 'pending_review'
                && (int) $draft->telegram_update_id === (int) ($payload['draft_generation'] ?? 0)
                && app(DraftAccess::class)->belongsToContext($draft, $update)) {
                $presenter->sendReview($telegram, $chat, $draft, $footnote);

                return 'sent';
            }

            return 'skipped_stale';
        }
        if (! empty($payload['deletion_id'])) {
            $deletion = AiOperation::whereKey($payload['deletion_id'])->where('telegram_update_id', $update->id)
                ->where('telegram_user_id', $update->telegram_user_id)->where('action', 'delete_product')
                ->where('status', 'awaiting_confirmation')->first();
            if ($deletion && (int) data_get($deletion->payload, 'expires_at', 0) > now()->getTimestamp()) {
                $title = (string) data_get($deletion->payload, 'title', 'Товар');
                $telegram->sendMessage($chat, "Подтвердите безвозвратное удаление:\n\n#{$deletion->target_id} · {$title}\n\nБудут удалены товар, варианты, характеристики и локальные фотографии.".$footnote,
                    ['inline_keyboard' => [[['text' => 'Удалить навсегда', 'callback_data' => "product:delete:confirm:{$deletion->id}"],
                        ['text' => 'Отмена', 'callback_data' => "product:delete:cancel:{$deletion->id}"]]]]);

                return 'sent';
            }

            return 'skipped_stale';
        }
        if (! empty($payload['failed_draft_id'])) {
            $draft = ProductDraft::find($payload['failed_draft_id']);
            if ($draft && $draft->status === 'rejected'
                && (int) $draft->telegram_update_id === (int) ($payload['failed_draft_generation'] ?? 0)
                && app(DraftAccess::class)->belongsToContext($draft, $update)) {
                $telegram->sendMessage($chat, "Поиск не подготовил готовую карточку «{$draft->title}». Черновик #{$draft->id} сохранён.\n".(string) $draft->rejection_reason.$footnote);

                return 'sent';
            }

            return 'skipped_stale';
        }
        $data = (array) ($payload['response'] ?? []);
        $telegram->sendMessage($chat, (string) ($data['message'] ?? '').$footnote);
        if (($data['response_type'] ?? '') === 'catalog_results' && ! empty($data['product_ids'])) {
            $cards->sendMany($telegram, $chat, $data['product_ids'], telegramUserId: $update->telegram_user_id);
        }

        return 'sent';
    }
}
