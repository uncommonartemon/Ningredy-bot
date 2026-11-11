<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who the shop buys from (operator, 2026-11-11: «поставщик, пускай будет селект с + добавить
 * на месте»). The list is what the admin picks from; a unit keeps the supplier's name itself,
 * as it keeps its product's, so the books stay readable whatever happens to the list.
 */
class Supplier extends Model
{
    protected $fillable = ['name', 'note'];

    protected static function booted(): void
    {
        // Renamed in the list, renamed on the units bought from it: the books name a supplier one way.
        static::updated(function (Supplier $supplier): void {
            if ($supplier->wasChanged('name')) {
                StockUnit::query()->where('supplier', $supplier->getOriginal('name'))->toBase()->update(['supplier' => $supplier->name]);
            }
        });
    }

    /** The units bought from this supplier: tied by the name a unit keeps. */
    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class, 'supplier', 'name');
    }

    /**
     * The names for a select; the name is also the value, because the name is what a unit keeps.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::query()->orderBy('name')->pluck('name', 'name')->all();
    }

    /**
     * The name as the list writes it: found whatever the letter case, added when it is new.
     * Whoever names a supplier - the form, a button, the chat - names one from the list.
     */
    public static function named(?string $name, ?string $note = null): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        if ($name === '') {
            return null;
        }
        $known = static::query()->pluck('name')->first(fn (string $listed): bool => mb_strtolower($listed) === mb_strtolower($name));

        return $known ?? static::query()->create(['name' => mb_substr($name, 0, 255), 'note' => $note])->name;
    }
}
