<?php

namespace App\Services\Products;

final class CatalogLocales
{
    /** Languages of the admin interface (not of the catalog's own text). */
    public const LABELS = ['ru' => 'Русский', 'cs' => 'Čeština', 'uk' => 'Українська', 'en' => 'English'];

    /**
     * The language every product's own text is written in: title, description,
     * specification names and values. A storefront locale without its own
     * translation shows this text, never another locale's translation.
     */
    public const CONTENT_SOURCE = 'en';

    /** The languages the card assembly translates that text into. */
    public const CONTENT_TRANSLATIONS = ['cs' => 'Čeština'];

    public static function supported(string $locale): bool
    {
        return array_key_exists($locale, self::LABELS);
    }

    /**
     * Only the title/description part of a card's translations - what a
     * product or variant stores. Specification translations live on each
     * attribute value instead.
     *
     * @param  array<string, mixed>|null  $translations
     * @return array<string, array{title: string, description: string}>|null
     */
    public static function textTranslations(?array $translations): ?array
    {
        if ($translations === null) {
            return null;
        }

        $text = [];
        foreach (array_keys(self::CONTENT_TRANSLATIONS) as $locale) {
            $entry = $translations[$locale] ?? null;
            if (is_array($entry) && is_string($entry['title'] ?? null) && is_string($entry['description'] ?? null)) {
                $text[$locale] = ['title' => $entry['title'], 'description' => $entry['description']];
            }
        }

        return $text;
    }

    /**
     * Specification translations of a card, as locale => key => {name, value}.
     *
     * @param  array<string, mixed>|null  $translations
     * @return array<string, array<string, array{name: string, value: string}>>
     */
    public static function specificationTranslations(?array $translations): array
    {
        $byLocale = [];
        foreach (array_keys(self::CONTENT_TRANSLATIONS) as $locale) {
            foreach ((array) ($translations[$locale]['specifications'] ?? []) as $item) {
                if (is_array($item) && is_string($item['key'] ?? null)
                    && is_string($item['name'] ?? null) && trim($item['name']) !== ''
                    && is_string($item['value'] ?? null) && trim($item['value']) !== '') {
                    $byLocale[$locale][$item['key']] = ['name' => trim($item['name']), 'value' => trim($item['value'])];
                }
            }
        }

        return $byLocale;
    }
}
