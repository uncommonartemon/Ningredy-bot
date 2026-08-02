<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use Throwable;

class ProductSourceAttemptRecorder
{
    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): ?ProductSourceAttempt
    {
        try {
            $url = (string) ($attributes['product_url'] ?? '');
            $telegramUpdateId = isset($attributes['telegram_update_id'])
                ? (int) $attributes['telegram_update_id']
                : null;
            $draftId = isset($attributes['product_draft_id'])
                ? (int) $attributes['product_draft_id']
                : null;

            if (! $draftId && $telegramUpdateId) {
                $draftId = ProductDraft::query()
                    ->where('telegram_update_id', $telegramUpdateId)
                    ->latest('id')
                    ->value('id');
            }

            return ProductSourceAttempt::query()->create([
                ...$attributes,
                'telegram_update_id' => $telegramUpdateId,
                'product_draft_id' => $draftId,
                'domain' => ProductSourcePriority::host($url),
                'product_url' => $url,
                'actor' => (string) ($attributes['actor'] ?? 'system'),
                'phase' => (string) ($attributes['phase'] ?? 'unknown'),
                'action' => (string) ($attributes['action'] ?? 'unknown'),
                'status' => (string) ($attributes['status'] ?? 'info'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
