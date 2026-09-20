<?php

namespace App\Models;

use App\Services\Ai\AiSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDraft extends Model
{
    protected $fillable = [
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

    /**
     * A reconciliation confirmation is a claim about ONE exact set of card
     * values against ONE exact source - a matching source_url alone does
     * not mean the values it was confirmed against are still the ones on
     * the draft. Any save that changes what was actually confirmed outside
     * ProductImageStorage's own reconciliation write - a manual edit, a
     * hint - invalidates the stamp rather than leaving it pointing at
     * values that no longer match what it once verified.
     */
    /**
     * Whether this draft is still waiting for its card to be reconciled
     * against the source its photos came from - the one question every gate
     * that withholds a draft from review or publication actually asks.
     *
     * It lives here because seven separate places used to ask it by
     * comparing the two columns inline (the approval workflow, the Telegram
     * presenter and its buttons, the research tool, both gallery jobs, the
     * Filament publish action). That made AiSettings::specificationReconciliation
     * Enabled() a switch that turned the checker off without turning the
     * BLOCKING off: no run would ever write the stamp again, and every gate
     * kept reading a stamp that could no longer arrive, so every draft stayed
     * blocked for ever. A kill switch whose only effect is to wedge the
     * pipeline shut is not a kill switch - so the switch is answered here,
     * once, for all of them.
     */
    public function reconciliationPending(): bool
    {
        if (! app(AiSettings::class)->specificationReconciliationEnabled()) {
            return false;
        }

        return $this->gallery_search_stop_reason === 'specifications_unreconciled'
            || (trim((string) $this->primary_source_url) !== ''
                && $this->specifications_reconciled_source_url !== $this->primary_source_url);
    }

    protected static function booted(): void
    {
        static::saving(function (ProductDraft $draft): void {
            if (static::$writingReconciliation || $draft->isDirty('specifications_reconciled_source_url')) {
                return;
            }

            $confirmedFields = ['title', 'model', 'color', 'description', 'specifications'];

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
