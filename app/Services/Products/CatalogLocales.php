<?php

namespace App\Services\Products;

use App\Models\AppSetting;
use Throwable;

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

    /**
     * Every language a card's text can be translated into. Which of them the bot
     * makes is a setting (admin: «Настройки → Языки каталога»); a translation that
     * exists stays stored even when its language is switched off later.
     */
    public const TRANSLATION_OPTIONS = ['cs' => 'Čeština', 'uk' => 'Українська', 'ru' => 'Русский'];

    /** Operator, 2026-11-01: Czech and Ukrainian unless the admin says otherwise. */
    public const DEFAULT_TRANSLATIONS = ['cs', 'uk'];

    /** The languages the storefront has its own interface texts for. */
    public const STOREFRONT_OPTIONS = ['cs' => 'Čeština', 'uk' => 'Українська', 'en' => 'English'];

    /** Operator, 2026-11-01: «англ не обязателен» - English is the source, not a shop language by default. */
    public const DEFAULT_STOREFRONT = ['cs', 'uk'];

    /** The languages the operator reads, in the order a Telegram card prefers them. */
    private const OPERATOR_READS = ['ru', 'uk', 'cs'];

    public const SETTING_TRANSLATIONS = 'catalog.content_translations';

    public const SETTING_STOREFRONT = 'catalog.storefront_locales';

    public const SETTING_TELEGRAM_CARD = 'catalog.telegram_card_locale';

    public static function supported(string $locale): bool
    {
        return array_key_exists($locale, self::LABELS);
    }

    /**
     * The languages the bot translates a card into, as locale => label.
     *
     * @return array<string, string>
     */
    public static function contentTranslations(): array
    {
        return array_intersect_key(self::TRANSLATION_OPTIONS,
            array_flip(self::stored(self::SETTING_TRANSLATIONS, self::DEFAULT_TRANSLATIONS, self::TRANSLATION_OPTIONS)));
    }

    /**
     * The storefront's languages; the first is the shop's main language, the one a
     * missing translation falls back to before the English source.
     *
     * @return array<int, string>
     */
    public static function storefrontLocales(): array
    {
        return self::stored(self::SETTING_STOREFRONT, self::DEFAULT_STOREFRONT, self::STOREFRONT_OPTIONS) ?: self::DEFAULT_STOREFRONT;
    }

    /** The language a card is shown in to the operator in Telegram: a translation the bot makes, or the source. */
    public static function telegramCardLocale(): string
    {
        $made = array_keys(self::contentTranslations());
        $stored = self::setting(self::SETTING_TELEGRAM_CARD);
        if ($stored === self::CONTENT_SOURCE || in_array($stored, $made, true)) {
            return $stored;
        }

        return collect(self::OPERATOR_READS)->first(fn (string $locale): bool => in_array($locale, $made, true))
            ?? $made[0] ?? self::CONTENT_SOURCE;
    }

    /**
     * @param  array<int, string>  $translations
     * @param  array<int, string>  $storefront
     */
    public static function save(array $translations, array $storefront, string $telegramCard): void
    {
        $translations = array_values(array_intersect(array_keys(self::TRANSLATION_OPTIONS), $translations));
        $storefront = array_values(array_intersect(array_keys(self::STOREFRONT_OPTIONS), $storefront)) ?: self::DEFAULT_STOREFRONT;
        AppSetting::put(self::SETTING_TRANSLATIONS, json_encode($translations));
        AppSetting::put(self::SETTING_STOREFRONT, json_encode($storefront));
        AppSetting::put(self::SETTING_TELEGRAM_CARD,
            $telegramCard === self::CONTENT_SOURCE || in_array($telegramCard, $translations, true) ? $telegramCard : null);
    }

    /**
     * A card's field in the given language when it has it, else its own (source) text.
     *
     * @param  array<string, mixed>|null  $translations
     */
    public static function cardText(?array $translations, string $field, ?string $source, string $locale): string
    {
        $translated = $locale === self::CONTENT_SOURCE ? null : ($translations[$locale][$field] ?? null);

        return is_string($translated) && trim($translated) !== '' ? $translated : (string) $source;
    }

    /**
     * @param  array<int, string>  $default
     * @param  array<string, string>  $options
     * @return array<int, string>
     */
    private static function stored(string $key, array $default, array $options): array
    {
        $raw = self::setting($key);
        $list = $raw === null ? $default : json_decode($raw, true);

        return array_values(array_intersect(array_keys($options), is_array($list) ? $list : $default));
    }

    private static function setting(string $key): ?string
    {
        try {
            return AppSetting::valueFor($key);
        } catch (Throwable) {
            // Before migrations, or without a database at all: the defaults hold.
            return null;
        }
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
        foreach (array_keys(self::TRANSLATION_OPTIONS) as $locale) {
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
        foreach (array_keys(self::TRANSLATION_OPTIONS) as $locale) {
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
