<?php

namespace App\Services\Telegram;

use App\Models\TelegramChatState;

final class TelegramConversationScope
{
    public static function key(string $chat, string $user): string
    {
        // Telegram groups have negative IDs; a private chat belongs to one user.
        return str_starts_with($chat, '-') ? $chat.':'.$user : $chat;
    }

    public static function clear(string $chat, string $user): void
    {
        TelegramChatState::query()->whereIn('chat_id', [$chat, self::key($chat, $user)])
            ->where(function ($query) use ($chat, $user): void {
                $query->where('telegram_user_id', $user);
                if (! str_starts_with($chat, '-')) {
                    $query->orWhereNull('telegram_user_id');
                }
            })->delete();
    }
}
