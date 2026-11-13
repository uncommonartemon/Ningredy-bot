<?php

namespace App\Services\Products;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Services\Catalog\AttributeDictionary;

/**
 * The characteristics a category requires of every card (the «обязательные
 * характеристики» of a kind of component: capacity, form factor, interface…).
 * Research is told their keys; whatever it did not find is named on the card
 * instead of being left out in silence (operator, 2026-11-04: a card of a
 * part without its form factor or interface is not a finished card).
 */
final class RequiredSpecifications
{
    /**
     * The required characteristics the given specifications do not carry.
     *
     * @param  array<int, mixed>  $specifications  rows with key and value
     * @return list<array{key: string, name: string}>
     */
    public static function missing(?Category $category, array $specifications, ?string $locale = null): array
    {
        if ($category === null) {
            return [];
        }
        // A required characteristic named another way is present all the same.
        $dictionary = app(AttributeDictionary::class);
        $present = collect($specifications)
            ->filter(fn (mixed $row): bool => is_array($row) && is_scalar($row['value'] ?? null) && trim((string) $row['value']) !== '')
            ->map(fn (array $row): string => strtolower(trim((string) ($dictionary->find(
                is_string($row['key'] ?? null) ? $row['key'] : null, is_string($row['name'] ?? null) ? $row['name'] : null)?->key ?? ($row['key'] ?? '')))))
            ->flip();

        return $category->requiredAttributeDefinitions()->get()
            ->reject(fn (AttributeDefinition $definition): bool => $present->has(strtolower($definition->key)))
            ->map(fn (AttributeDefinition $definition): array => ['key' => $definition->key, 'name' => self::name($definition, $locale)])
            ->values()->all();
    }

    /** @return list<array{key: string, name: string}> */
    public static function forDraft(ProductDraft $draft, ?string $locale = null): array
    {
        $slug = trim((string) $draft->category);
        $category = $slug === '' ? null : Category::query()->where('slug', $slug)->first();

        return self::missing($category, (array) ($draft->specifications ?? []), $locale);
    }

    /** The card's line about what research did not find, in the card's language; null when nothing is missing. */
    public static function cardLine(ProductDraft $draft): ?string
    {
        $missing = self::forDraft($draft, CatalogLocales::telegramCardLocale());

        return $missing === [] ? null : '❓ Не найдено: '.implode(', ', array_column($missing, 'name'));
    }

    private static function name(AttributeDefinition $definition, ?string $locale): string
    {
        $translations = is_array($definition->translations) ? $definition->translations : [];
        $name = $locale === null ? null : ($translations[$locale] ?? null);

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) $definition->label;
    }
}
