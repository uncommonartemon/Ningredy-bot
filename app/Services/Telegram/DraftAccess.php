<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;

class DraftAccess
{
    public function belongsToContext(ProductDraft $draft, TelegramUpdate $update): bool
    {
        $owner = (string) $draft->requested_by_telegram_user_id;
        $chat = (string) ($draft->telegramUpdate?->chat_id ?: $draft->telegram_review_chat_id);

        return ($owner === '' || $owner === (string) $update->telegram_user_id)
            && ($chat === '' || $chat === (string) $update->chat_id);
    }
}
