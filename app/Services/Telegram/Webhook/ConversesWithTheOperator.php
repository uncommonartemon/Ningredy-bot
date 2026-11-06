<?php

namespace App\Services\Telegram\Webhook;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftAccess;
use App\Services\Telegram\DraftQueuedWork;
use App\Services\Telegram\DraftTelegramActionConfirmation;
use App\Services\Telegram\DraftTelegramInteractionState;
use Illuminate\Support\Facades\Cache;

/** What every handler of the webhook says and checks the same way. */
trait ConversesWithTheOperator
{
    private function mainKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '🔎 Найти товар'], ['text' => '📦 Каталог']],
                [['text' => '📥 Загрузить товар']],
                [['text' => '📋 Черновики'], ['text' => '🖥 Статус']],
                [['text' => '⚠️ Ошибки'], ['text' => 'ℹ️ Помощь']],
                [['text' => '🛠 Админка'], ['text' => '🔄 Сброс']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function markCommandProcessed(TelegramUpdate $update): void
    {
        $update->update(['status' => 'command', 'processed_at' => now()]);
    }

    /** Answers the press and closes the update: an open one would count as hung on /reset. */
    private function answerAndClose(string $callbackId, TelegramUpdate $update, string $text): void
    {
        $this->telegram->answerCallbackQuery($callbackId, $text);
        $this->markCommandProcessed($update);
    }

    private function requestDraftActionConfirmation(ProductDraft $draft, TelegramUpdate $update, string $callback, array $extra = []): void
    {
        $terms = app(DraftTelegramActionConfirmation::class)->describe($callback);
        $state = app(DraftTelegramInteractionState::class)->remember((string) $update->chat_id, (string) $update->telegram_user_id,
            $draft, 'confirmation', [...$extra, 'callback' => $callback, 'budget' => $terms['budget']]);
        $preview = $extra['query'] ?? $extra['hint'] ?? null;
        $description = $terms['description'].($preview !== null ? "\n\nВаш текст:\n".mb_substr($preview, 0, 2000)
            .(mb_strlen($preview) > 2000 ? "\n… (показано начало; будет передан весь ваш текст)" : '') : '');
        $this->draftPresenter->sendActionConfirmation($this->telegram, (string) $update->chat_id, $draft,
            $state['token'], $terms['title'], $description, $terms['label']);
    }

    private function draftHasQueuedWork(int $draftId): bool
    {
        // The same markers the existing jobs clear on completion/failure; the chat
        // tools read them too. No persistent lock or timeout of the UI's own.
        return DraftQueuedWork::has($draftId);
    }

    private function clearManualCreate(string $chat, string $user): void
    {
        Cache::forget("telegram-manual-create:{$chat}:{$user}");
        Cache::forget("telegram-manual-create:{$chat}:{$user}:expires_at");
    }

    private function forgetExpiredInput(string $chat, string $user): void
    {
        app(DraftTelegramInteractionState::class)->clear($chat, $user);
        $this->clearManualCreate($chat, $user);
    }

    private function expiredManualInput(string $chat, string $user): bool
    {
        $createKey = "telegram-manual-create:{$chat}:{$user}";
        $deadline = Cache::get($createKey.':expires_at');

        return app(DraftTelegramInteractionState::class)->expired($chat, $user) !== null
            || (! Cache::has($createKey) && is_numeric($deadline) && (int) $deadline <= now()->getTimestamp());
    }

    private function draftBelongsToContext(ProductDraft $draft, TelegramUpdate $update): bool
    {
        return app(DraftAccess::class)->belongsToContext($draft, $update);
    }
}
