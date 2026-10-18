<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Services\ProductSearch\Recovery\FailureDiagnosis;
use Throwable;

class ProductSourceAttemptRecorder
{
    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): ?ProductSourceAttempt
    {
        try {
            if (in_array($attributes['status'] ?? null, ['failed', 'interrupted', 'blocked'], true)
                && ! isset($attributes['output']['failure_diagnosis'])) {
                $attributes['output'] = [...($attributes['output'] ?? []), 'failure_diagnosis' => FailureDiagnosis::make(
                    (string) ($attributes['phase'] ?? 'unknown'),
                    (string) ($attributes['output']['repair_context']['failure_kind'] ?? $attributes['output']['reason'] ?? $attributes['decision'] ?? 'operation'),
                    serverReason: ($attributes['actor'] ?? 'system') === 'agent' ? null : ($attributes['message'] ?? null),
                    agentComment: ($attributes['actor'] ?? 'system') === 'agent' ? ($attributes['message'] ?? null) : null,
                )];
            }
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

    /**
     * Nested resolver/trainer services only know the Telegram update that
     * started the current search cycle. During continuation that update is
     * the callback, while the draft still points at its original message, so
     * recorder inference cannot bind the attempts on its own. Attach every
     * attempt created by this exact nested call before continuation logic
     * decides whether the source is terminal or retryable.
     */
    public function associateWithDraftSince(
        ProductDraft $draft,
        ?int $telegramUpdateId,
        int $afterId,
    ): void {
        if (! $telegramUpdateId) {
            return;
        }

        ProductSourceAttempt::query()
            ->where('id', '>', $afterId)
            ->where('telegram_update_id', $telegramUpdateId)
            ->whereNull('product_draft_id')
            ->update(['product_draft_id' => $draft->id]);
    }
}
