<?php

namespace App\Services\Telegram;

use App\Models\AppSetting;

class TelegramOperatorAccess
{
    public function allows(mixed $telegramUserId): bool
    {
        $stored = AppSetting::valueFor(AppSetting::TELEGRAM_ALLOWED_USER_IDS);
        $allowed = $stored !== null
            ? array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $stored) ?: [])))
            : (array) config('services.telegram.allowed_user_ids', []);

        return $allowed !== [] && in_array((string) $telegramUserId, array_map('strval', $allowed), true);
    }
}
