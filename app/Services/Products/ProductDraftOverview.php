<?php

namespace App\Services\Products;

use App\Models\ProductDraft;
use App\Services\Telegram\DraftTelegramPresenter;

/**
 * One draft as the chat assistant sees it: what its card says and what the
 * card cannot show - whether the characteristics are reconciled, which
 * required ones are missing, how it differs from the request - so a question
 * about a draft is answered from that draft, never from the list (module 72).
 */
class ProductDraftOverview
{
    private const array STATUS_TEXT = ['pending_review' => 'ожидает решения', 'approved' => 'добавлен в каталог', 'rejected' => 'отклонён'];

    /** @return array<string, mixed> */
    public function forChat(ProductDraft $draft): array
    {
        $draft->loadMissing(['media', 'telegramUpdate']);
        $specifications = collect(is_array($draft->specifications) ? $draft->specifications : [])
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['value']))
            ->map(fn (array $row): array => array_filter([
                'key' => $row['key'] ?? null,
                'name' => $row['name'] ?? ($row['key'] ?? null),
                'value' => $row['value'],
                'group' => $row['group'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()->take(120)->all();
        $unreconciled = app(DraftTelegramPresenter::class)->unreconciledFields($draft);

        return [
            'draft_id' => $draft->id,
            'status' => $draft->status,
            'status_text' => self::STATUS_TEXT[$draft->status] ?? 'обрабатывается',
            'created_at' => $draft->created_at?->toDateTimeString(),
            'request' => $draft->telegramUpdate?->text,
            'title' => $draft->title,
            'brand' => $draft->brand,
            'model' => $draft->model,
            'color' => $draft->color,
            'condition' => $draft->condition,
            'category' => $draft->category,
            'product_type' => $draft->product_type,
            'description' => $draft->description,
            'specifications' => $specifications,
            'specifications_reconciled' => $draft->specifications_reconciled_source_url !== null,
            'unreconciled_fields' => array_map(fn (array $field): string => (string) ($field['name'] ?? $field['key']), $unreconciled),
            'missing_required_specifications' => RequiredSpecifications::forDraft($draft),
            'request_differences' => data_get($draft->telegramUpdate?->payload, ProductRequestContext::DIFFERENCES_KEY),
            'photos' => [
                'count' => $draft->media->count(),
                'verified' => $draft->media->where('verification_status', 'verified')->count(),
                'gallery_status' => $draft->gallery_status,
                'stop_reason' => $draft->gallery_search_stop_reason,
                'notes' => $draft->gallery_notes,
            ],
            'sources' => array_values(array_filter([$draft->primary_source_url, $draft->official_source_url])),
            'research_notes' => $draft->research_notes,
            'manual_upload' => $draft->isManualUpload(),
            'product_id' => $draft->approved_product_id,
            'rejection_reason' => $draft->rejection_reason,
        ];
    }
}
