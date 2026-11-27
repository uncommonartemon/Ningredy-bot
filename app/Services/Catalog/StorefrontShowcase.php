<?php

namespace App\Services\Catalog;

use App\Models\AppSetting;
use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The products the shop puts on its start page, in its own order (admin «Настройки → Витрина»,
 * or from the chat). The first three are the plates of the first screen; all of them lead the
 * row of new products. A product deleted later simply drops out of the list; one taken off sale
 * or without a photo stays chosen but is skipped by the page until it is shown again.
 */
final class StorefrontShowcase
{
    public const SETTING = 'storefront.home_products';

    public const LIMIT = 12;

    /** @return list<int> the chosen products that still exist, in the shop's order */
    public static function ids(): array
    {
        $stored = json_decode((string) AppSetting::valueFor(self::SETTING), true);
        $ids = self::clean(is_array($stored) ? $stored : []);
        if ($ids->isEmpty()) {
            return [];
        }
        $existing = Product::query()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id)->flip();

        return $ids->filter(fn (int $id): bool => $existing->has($id))->take(self::LIMIT)->values()->all();
    }

    /**
     * Replaces the list. Every product must exist; the order given is the order shown.
     *
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    public static function save(array $ids): array
    {
        $ids = self::clean($ids);
        if ($ids->count() > self::LIMIT) {
            throw new InvalidArgumentException('На главной не больше '.self::LIMIT.' товаров, выбрано '.$ids->count().'.');
        }
        $existing = Product::query()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $missing = $ids->diff($existing);
        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException('Таких товаров нет: '.$missing->implode(', ').'.');
        }
        AppSetting::put(self::SETTING, $ids->isEmpty() ? null : json_encode($ids->values()->all()));

        return $ids->values()->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<int, int>
     */
    private static function clean(array $ids): Collection
    {
        return collect($ids)->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit(trim($id))))
            ->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values();
    }
}
