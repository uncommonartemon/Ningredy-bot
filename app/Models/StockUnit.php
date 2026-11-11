<?php

namespace App\Models;

use App\Services\Catalog\StockUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical item (operator, 2026-11-11): its own number, what it was bought for, when and
 * from whom, and what became of it. Kept for the shop's own books; the storefront never shows it.
 * A unit without a configuration is one whose product was deleted from the catalog: it stays as history.
 */
class StockUnit extends Model
{
    public const string IN_STOCK = 'in_stock';

    public const string SOLD = 'sold';

    public const string WRITTEN_OFF = 'written_off';

    public const array STATUSES = [self::IN_STOCK, self::SOLD, self::WRITTEN_OFF];

    protected $fillable = [
        'product_variant_id', 'serial_number', 'purchase_price', 'purchased_at', 'supplier', 'note', 'status', 'sold_at', 'sold_price',
        'product_title', 'currency',
    ];

    protected $attributes = ['status' => self::IN_STOCK];

    protected function casts(): array
    {
        return ['purchase_price' => 'decimal:2', 'sold_price' => 'decimal:2', 'purchased_at' => 'date', 'sold_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // A unit carries its product's name and currency: the books read them when the product is gone.
        static::creating(function (StockUnit $unit): void {
            if ($unit->product_title === null || $unit->currency === null) {
                $variant = $unit->variant()->with('product:id,title')->first();
                $unit->product_title ??= $variant?->product?->title;
                $unit->currency ??= $variant?->currency;
            }
        });
        // The number comes from the row's own id: unique, and never reused.
        static::created(function (StockUnit $unit): void {
            $unit->forceFill(['code' => 'U-'.str_pad((string) $unit->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        });
        // A unit that left the stock or came back changes its configuration's quantity.
        static::saved(function (StockUnit $unit): void {
            if ($unit->wasRecentlyCreated || $unit->wasChanged('status') || $unit->wasChanged('product_variant_id')) {
                app(StockUnits::class)->unitChanged($unit);
            }
        });
        static::deleted(fn (StockUnit $unit) => app(StockUnits::class)->unitChanged($unit));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', self::IN_STOCK);
    }

    /** Nothing was written about the unit yet: it exists only because the quantity says so. */
    public function isBlank(): bool
    {
        return blank($this->serial_number) && $this->purchase_price === null && $this->purchased_at === null
            && blank($this->supplier) && blank($this->note);
    }
}
