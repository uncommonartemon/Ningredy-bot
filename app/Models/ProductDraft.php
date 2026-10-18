<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ProductDraft extends Model
{
    protected $fillable = [
        'condition', 'translations', 'specification_evidence', 'photo_source_context', 'card_repair_state',
        'telegram_update_id', 'ai_run_id', 'status', 'reviewed_at', 'reviewed_by_user_id',
        'reviewed_by_telegram_user_id', 'rejection_reason', 'requested_by_telegram_user_id',
        'approved_product_id', 'approved_variant_id',
        'title', 'brand', 'model', 'product_type', 'category', 'color', 'description', 'research_notes', 'specifications',
        'sources', 'primary_source_url', 'specifications_reconciled_source_url',
        'specifications_reconciliation_attempts', 'gallery_confirmed_sufficient', 'official_source_url', 'image_urls',
        'excluded_gallery_source_urls', 'excluded_gallery_image_urls', 'excluded_gallery_hashes',
        'telegram_review_chat_id', 'telegram_review_message_ids', 'telegram_review_has_media', 'telegram_review_caption',
        'telegram_control_message_ids', 'telegram_review_finalized_at',
        'images_staged_at', 'gallery_status', 'gallery_notes', 'gallery_search_stop_reason', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'translations' => 'array', 'specification_evidence' => 'array', 'photo_source_context' => 'array', 'card_repair_state' => 'array',
            'specifications' => 'array', 'sources' => 'array', 'image_urls' => 'array',
            'excluded_gallery_source_urls' => 'array',
            'excluded_gallery_image_urls' => 'array',
            'excluded_gallery_hashes' => 'array',
            'telegram_review_message_ids' => 'array',
            'telegram_review_has_media' => 'boolean',
            'gallery_confirmed_sufficient' => 'boolean',
            'telegram_control_message_ids' => 'array',
            'telegram_review_finalized_at' => 'datetime',
            'confidence' => 'decimal:4',
            'reviewed_at' => 'datetime',
            'images_staged_at' => 'datetime',
        ];
    }

    /**
     * True only for the duration of ProductImageStorage's own reconciliation
     * writes (see withReconciliationWrite()). Ordinarily, a save that also
     * sets specifications_reconciled_source_url in the very same call
     * (isDirty on that column) is exempt below - that is what a save
     * confirming a NEW source looks like. It is not what a save
     * re-confirming the SAME source again looks like: the column's value
     * does not change, so isDirty on it is false even though this genuinely
     * is a reconciliation write - this flag is the exemption for exactly
     * that case, one only ProductImageStorage's own trusted call sites set.
     */
    private static bool $writingReconciliation = false;

    /** @param \Closure(): mixed $callback */
    public static function withReconciliationWrite(\Closure $callback): mixed
    {
        static::$writingReconciliation = true;

        try {
            return $callback();
        } finally {
            static::$writingReconciliation = false;
        }
    }

    /** Disabling new checks does not manufacture confirmation of this card. */
    public function reconciliationPending(): bool
    {
        // Manual intake has its own explicit readiness and file checks. Missing
        // provenance on an AI/legacy draft must never manufacture approval.
        if ($this->isManualUpload()) {
            return false;
        }

        return $this->gallery_search_stop_reason === 'specifications_unreconciled'
            || trim((string) $this->primary_source_url) === ''
            || $this->specifications_reconciled_source_url !== $this->primary_source_url;
    }

    /** Verified local photos available for a Telegram preview, not proof of card readiness. */
    public function verifiedPhotoPaths(): array
    {
        $this->loadMissing('media');

        return $this->media
            ->sortBy([['is_primary', 'desc'], ['sort_order', 'asc']])
            ->filter(fn ($media): bool => in_array($media->verification_status, ['verified', 'source_verified'], true)
                && filled($media->disk) && filled($media->path))
            ->map(fn ($media): string => Storage::disk($media->disk)->path($media->path))
            ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
            ->take(10)->values()->all();
    }

    public function isManualUpload(): bool
    {
        return (bool) data_get($this->telegramUpdate?->payload, '_manual_product_upload', false);
    }

    /** Original operator material and commercial terms stay separate from web evidence. */
    public function manualIntake(): array
    {
        $intake = data_get($this->telegramUpdate?->payload, '_manual_product_intake', []);

        return is_array($intake) ? $intake : [];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductDraft $draft): void {
            if (static::$writingReconciliation || $draft->isDirty('specifications_reconciled_source_url')) {
                return;
            }

            $confirmedFields = ['title', 'brand', 'model', 'product_type', 'category', 'color', 'condition',
                'description', 'translations', 'specifications', 'specification_evidence',
                'sources', 'primary_source_url', 'official_source_url'];

            if (collect($confirmedFields)->contains(fn (string $field): bool => $draft->isDirty($field))) {
                $draft->specifications_reconciled_source_url = null;
                $draft->specifications_reconciliation_attempts = 0;
            }
        });
    }

    public function telegramUpdate(): BelongsTo
    {
        return $this->belongsTo(TelegramUpdate::class);
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'approved_product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'approved_variant_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductDraftMedia::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }
}
