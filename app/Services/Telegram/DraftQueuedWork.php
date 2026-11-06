<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * The markers the draft jobs set while they work on a draft and clear when they
 * finish or fail. The buttons and the chat tools read the same markers; neither
 * adds a lock or a timeout of its own.
 */
class DraftQueuedWork
{
    public const array OPERATIONS = ['draft-gallery-retrain', 'draft-gallery-restage', 'draft-gallery-continue', 'draft-gallery-topup',
        'draft-photo-actions', 'draft-fields', 'draft-enrich', 'draft-upload'];

    public static function has(int $draftId): bool
    {
        foreach (self::OPERATIONS as $operation) {
            if (Cache::has("{$operation}:{$draftId}:queued")) {
                return true;
            }
        }

        return false;
    }
}
