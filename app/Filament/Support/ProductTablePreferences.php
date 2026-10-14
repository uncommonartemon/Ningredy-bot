<?php

namespace App\Filament\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\User;

final class ProductTablePreferences
{
    public static function read(User $user): array
    {
        return self::normalize($user->fresh()?->product_table_filters ?? []);
    }

    public static function save(User $user, array $filters): void
    {
        $filters = self::normalize($filters);

        if ($user->product_table_filters === $filters) {
            return;
        }

        // Only this account's applied filters are writable, never arbitrary preferences.
        $user->newQuery()->whereKey($user->getKey())->update([
            'product_table_filters' => json_encode($filters, JSON_THROW_ON_ERROR),
        ]);
        $user->setAttribute('product_table_filters', $filters);
        $user->syncOriginalAttribute('product_table_filters');
    }

    public static function normalize(array $filters): array
    {
        $normalized = [];

        foreach (['status' => ProductOptions::statuses(), 'product_type' => ProductOptions::types()] as $name => $options) {
            $value = is_array($filters[$name] ?? null) ? ($filters[$name]['value'] ?? null) : null;

            if (is_string($value) && array_key_exists($value, $options)) {
                $normalized[$name] = ['value' => $value];
            }
        }

        foreach (['category_id' => Category::class, 'brand_id' => Brand::class] as $name => $model) {
            $value = is_array($filters[$name] ?? null) ? ($filters[$name]['value'] ?? null) : null;

            if ((is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value)
                && $model::query()->whereKey($value)->exists()) {
                $normalized[$name] = ['value' => (string) $value];
            }
        }

        foreach (['is_active', 'is_featured'] as $name) {
            $value = is_array($filters[$name] ?? null) ? ($filters[$name]['value'] ?? null) : null;
            $value = match ($value) {
                true, 1, '1', 'true' => true,
                false, 0, '0', 'false' => false,
                default => null,
            };

            if ($value !== null) {
                $normalized[$name] = ['value' => $value];
            }
        }

        return $normalized;
    }
}
