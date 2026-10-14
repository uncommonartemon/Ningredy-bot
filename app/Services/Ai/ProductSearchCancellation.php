<?php

namespace App\Services\Ai;

use App\Exceptions\ProductSearchCancelled;
use App\Models\TelegramUpdate;

class ProductSearchCancellation
{
    public function isCancelled(?int $telegramUpdateId): bool
    {
        if ($telegramUpdateId === null) {
            return false;
        }

        // Never cache this: another webhook request may cancel while the
        // provider/browser is working. Null/missing IDs are not evidence of
        // cancellation (standalone browser probes may have no Telegram row).
        // Jobs validate their own request/draft generation separately.
        $update = TelegramUpdate::query()->find($telegramUpdateId, ['status', 'cancel_requested_at']);

        return $update !== null && ($update->status === 'cancelled' || $update->cancel_requested_at !== null);
    }

    public function throwIfCancelled(?int $telegramUpdateId): void
    {
        if ($this->isCancelled($telegramUpdateId)) {
            throw new ProductSearchCancelled;
        }
    }
}
