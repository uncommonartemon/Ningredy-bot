<?php

namespace App\Services\Telegram\Webhook;

use App\Jobs\ProposeTelegramDraftEdit;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Text a draft card is waiting for: a source hint, a new query, changed fields. */
class TelegramPendingInputHandler
{
    use ConversesWithTheOperator;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly DraftTelegramPresenter $draftPresenter,
    ) {}

    public function handle(string $text, string $chatId, TelegramUpdate $update): bool
    {
        $states = app(DraftTelegramInteractionState::class);
        $state = $states->get($chatId, (string) $update->telegram_user_id);
        // A button confirmation is not answered by text: a message during one is
        // the operator's next request and goes its normal way (live #153-#155),
        // while the confirmation keeps waiting on its buttons. The exception is
        // a short reply without a model number - "да", "ok", "подтверждаю" - which
        // is an attempt to answer the button, not a product: it gets the reminder
        // and never a paid search.
        if (($state['kind'] ?? '') === 'confirmation' && $this->readsAsShortReply($text)) {
            $this->telegram->sendMessage($chatId, 'Подтвердить или отменить можно только кнопкой под сообщением. /cancel — отменить действие. Текст не отправлен в новый поиск.');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'processing') {
            $this->telegram->sendMessage($chatId, 'Карточка сейчас обрабатывается. Дождитесь результата или /cancel. Текст не отправлен в новый поиск.');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'manual_photos') {
            $this->telegram->sendMessage($chatId, 'Сейчас ожидаю фото для выбранной карточки. Отправьте фото/альбом или /cancel, затем выберите «Исправить данные».');
            $this->markCommandProcessed($update);

            return true;
        }
        if (($state['kind'] ?? '') === 'manual_fields') {
            $state = $states->consume($chatId, (string) $update->telegram_user_id, $state['token']);
            $draft = $state ? ProductDraft::find($state['draft_id']) : null;
            if ($draft && $draft->status === 'pending_review'
                && (int) $draft->telegram_update_id === (int) $state['generation'] && $this->draftBelongsToContext($draft, $update)) {
                if ($this->draftHasQueuedWork($draft->id)) {
                    $states->remember($chatId, (string) $update->telegram_user_id, $draft, 'manual_fields');
                    $this->telegram->sendMessage($chatId, 'Карточка уже обрабатывается. Изменения пока не запущены. Дождитесь результата.');
                    $this->markCommandProcessed($update);

                    return true;
                }
                $processing = $states->remember($chatId, (string) $update->telegram_user_id, $draft, 'processing',
                    ['input_update_id' => $update->id, 'operation' => 'draft-fields']);
                Cache::put("draft-fields:{$draft->id}:queued", $update->id, 240);
                ProposeTelegramDraftEdit::dispatch($draft->id, $update->id, $draft->telegram_update_id, $text, $processing['token'])->afterCommit();
                $this->telegram->sendMessage($chatId, '✏️ Готовлю изменения для просмотра. Интернет-поиск не запускается.');
            } else {
                $this->telegram->sendMessage($chatId, 'Карточка уже недоступна. Новый поиск не запущен.');
                $this->markCommandProcessed($update);
            }

            return true;
        }
        if (! $state || ! in_array($state['kind'], ['source_hint', 'new_search'], true)) {
            return false;
        }
        $state = $states->consume($chatId, (string) $update->telegram_user_id, $state['token']);
        if (! $state) {
            $this->markCommandProcessed($update);

            return true;
        }

        $draft = ProductDraft::query()->find($state['draft_id']);

        if (! $draft || $draft->status !== 'pending_review' || ($state['kind'] === 'source_hint' && blank($draft->primary_source_url))
            || (int) $draft->telegram_update_id !== (int) $state['generation'] || ! $this->draftBelongsToContext($draft, $update)) {
            $this->telegram->sendMessage($chatId, 'Черновик, для которого ожидался текст, уже изменён или недоступен. Новый поиск не запущен.');
            $update->update(['status' => 'ignored', 'processed_at' => now()]);

            return true;
        }

        $isQuery = $state['kind'] === 'new_search';
        $limit = $isQuery ? 4000 : 1000;
        if (mb_strlen($text) > $limit) {
            $states->remember($chatId, (string) $update->telegram_user_id, $draft, $state['kind']);
            $this->telegram->sendMessage($chatId, "Текст слишком длинный: максимум {$limit} символов. Сократите его — ничего не запущено.");
            $this->markCommandProcessed($update);

            return true;
        }
        $this->requestDraftActionConfirmation($draft, $update,
            $isQuery ? "draft:new-search:{$draft->id}" : "draft:source-retrain:{$draft->id}",
            [$isQuery ? 'query' : 'hint' => $text, 'input_update_id' => $update->id]);
        $update->update(['status' => 'completed', 'processed_at' => now()]);

        return true;
    }

    /**
     * Consumes a pending "💬 Переобучить с подсказкой" prompt (see
     * handleDraftSourceHintPrompt) - the next plain-text message in that chat
     * is the operator's hint, not a new product search, so it must never
     * reach ServerAssistantAgent without explicit confirmation. Returns false when nothing is
     * pending, so the caller falls through to the normal AI dispatch.
     */
    /** Up to two words and no digit: a reply to a question, not a product. */
    private function readsAsShortReply(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && preg_match('/\d/u', $text) !== 1
            && count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 2;
    }
}
