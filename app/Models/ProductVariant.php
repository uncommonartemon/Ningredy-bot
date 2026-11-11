<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesStaleTextTranslations;
use App\Services\Catalog\StockUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use InvalidatesStaleTextTranslations;

    /** The name a card's only configuration gets when nothing names it (no colour). */
    public const DEFAULT_NAME = 'Базовая конфигурация';

    protected $fillable = [
        'translations',
        'product_id', 'fingerprint', 'name', 'sku', 'mpn', 'gtin', 'color', 'condition',
        'price', 'compare_at_price', 'currency', 'stock_status', 'quantity',
        'warranty_months', 'is_default', 'is_active', 'group_prices', 'stock_units',
    ];

    /** True between the insert and the «saved» that follows it: the one save that is the configuration's first. */
    private bool $justInserted = false;

    /** The product form's rows for the units in stock, written once the configuration is saved. */
    private ?array $pendingStockUnits = null;

    /** Prices for groups of customers given with the configuration, written once it is saved. */
    private ?array $pendingGroupPrices = null;

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant): void {
            if (blank($variant->fingerprint)) {
                $variant->fingerprint = sha1(implode('|', array_filter([
                    $variant->sku, $variant->mpn, $variant->gtin, $variant->name, $variant->color,
                    Str::uuid()->toString(),
                ])));
            }
        });
        // One configuration deleted by itself leaves the books what they know, as its product would.
        static::deleting(fn (ProductVariant $variant) => app(StockUnits::class)->variantsLeaving([(int) $variant->id]));
        static::created(function (ProductVariant $variant): void {
            $variant->justInserted = true;
        });
        static::saved(function (ProductVariant $variant): void {
            $variant->writePendingGroupPrices();
            // The quantity and the units in stock are the same number, whoever changed either.
            $rows = $variant->pendingStockUnits;
            $variant->pendingStockUnits = null;
            $inserted = $variant->justInserted;
            $variant->justInserted = false;
            app(StockUnits::class)->afterVariantSaved($variant, $rows, $inserted);
        });
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** The product form's rows for the units in stock; they are rows of their own, not a column. */
    public function setStockUnitsAttribute(mixed $rows): void
    {
        $this->pendingStockUnits = is_array($rows) ? $rows : [];
    }

    public function groupPrices(): HasMany
    {
        return $this->hasMany(CustomerGroupPrice::class);
    }

    /**
     * The admin form's «price for a group» fields: group id => price. They are not a column:
     * the prices are kept until the configuration itself is saved.
     */
    public function setGroupPricesAttribute(mixed $prices): void
    {
        $this->pendingGroupPrices = is_array($prices) ? $prices : [];
    }

    /** An empty price removes the group's own one, so the group sees the ordinary price again. */
    private function writePendingGroupPrices(): void
    {
        if ($this->pendingGroupPrices === null) {
            return;
        }
        $prices = $this->pendingGroupPrices;
        $this->pendingGroupPrices = null;
        foreach (CustomerGroup::query()->whereIn('id', array_keys($prices))->pluck('id') as $groupId) {
            $price = $prices[$groupId];
            if (is_numeric($price) && (float) $price >= 0) {
                $this->groupPrices()->updateOrCreate(['customer_group_id' => $groupId], ['price' => $price]);
            } else {
                $this->groupPrices()->where('customer_group_id', $groupId)->delete();
            }
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
