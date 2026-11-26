<?php

namespace App\Services\Products;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which texts of a product a buyer can read on the storefront without a translation into a
 * shop language (decision of 2026-11-27: a buyer reads a card only in the shop's languages;
 * the English source and Russian are never the text shown). The one place that knows it: the
 * card's translation request, the scheduler's catch-up, the status page's «Переводы» check,
 * the admin's «Без перевода» tab and the line at approval in Telegram all read it here.
 *
 * A text counts as translated into a language when a translation for its current source text
 * is stored, even one equal to the source (a model name): a source edited by hand drops its
 * translations (InvalidatesStaleTextTranslations, AttributeValue::saving), and an answer that
 * cannot stand as a translation is not stored at all (acceptable()).
 *
 * Names shared by many products (characteristics, their groups, categories, brand countries)
 * are listed too, as shared: CatalogNames fills them in one request for the whole catalog,
 * so a name no answer fills is not asked again once per product.
 */
final class CardTranslationState
{
    public const array SHARED_KINDS = ['spec_name', 'group_name', 'category_name', 'country_name'];

    /**
     * The languages every text a buyer reads must have: those the bot translates into, which
     * always include the storefront's own (CatalogLocales::contentTranslations()).
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        return array_keys(CatalogLocales::contentTranslations());
    }

    /**
     * Whether a stored string is text to translate. What is not text by its nature stays as
     * written in every language: model names and codes ("X1 Carbon", "MZ-V8V1T0", "i7-1360P"),
     * numbers with units ("16 GB", "2.4 GHz", "3840 x 2160") and technical abbreviations
     * ("DDR5", "NVMe", "IPS"). A string is text when it carries a word: a run of two lower-case
     * letters, a run of four letters, or a capital followed by a lower-case letter ("Yes",
     * "Up to 4400 MT/s", "Supported"). Cyrillic is always text: «16 ГБ», «ДА» and «один» are
     * words of a language, typed by the shop's own people, never a code. A short mixed-case unit
     * or abbreviation ("2.4 GHz", "NVMe") reads as a word to this rule: it is sent and comes back
     * as written, which costs a few words of a request and never leaves a text untranslated.
     */
    public static function isText(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        return preg_match('/\p{Cyrillic}/u', $text) === 1 || preg_match('/\p{Ll}{2,}|\p{L}{4,}|\p{Lu}\p{Ll}/u', $text) === 1;
    }

    /**
     * Whether a translator's answer may stand as the text's translation into the locale. An
     * answer equal to the source counts (a model name reads the same everywhere), except where
     * it cannot be one: a sales description always has words to translate, Czech and English
     * are not written in Cyrillic, and an answer with letters Ukrainian does not have is Russian.
     */
    public static function acceptable(string $kind, string $source, string $answer, string $locale): bool
    {
        $answer = trim($answer);
        if ($answer === '') {
            return false;
        }
        if (in_array($locale, ['cs', 'en'], true) && preg_match('/\p{Cyrillic}/u', $answer) === 1) {
            return false;
        }
        if ($locale === 'uk' && preg_match('/[ыэъёЫЭЪЁ]/u', $answer) === 1) {
            return false;
        }

        return ! ($kind === 'description' && $answer === trim($source));
    }

    /**
     * Every text of the product a buyer can read that lacks a language, keyed by a stable id,
     * with the languages it lacks and where its translation is written.
     *
     * @param  list<string>|null  $locales
     * @param  Collection<int, Category>|null  $categories  every category by id, when many products are read at once
     * @return array<string, array{id: string, kind: string, text: string, locales: list<string>, target: Model, field: ?string, shared: bool}>
     */
    public function missing(Product $product, ?array $locales = null, ?Collection $categories = null): array
    {
        $locales ??= self::locales();
        if ($locales === []) {
            return [];
        }
        $product->loadMissing(['variants.attributes.definition.group', 'attributes.definition.group', 'brand']);
        $items = [];
        // A stored translation that could not stand as one counts as none: answers stored before
        // the check (a Czech «один», the English description sent back) are asked for again.
        $add = function (string $id, string $kind, ?string $text, array $have, Model $target, ?string $field = null) use (&$items, $locales): void {
            $text = trim((string) $text);
            $lacking = array_values(array_filter($locales, fn (string $locale): bool => ! (is_string($have[$locale] ?? null)
                && self::acceptable($kind, $text, $have[$locale], $locale))));
            if ($lacking !== [] && self::isText($text)) {
                $items[$id] = ['id' => $id, 'kind' => $kind, 'text' => $text, 'locales' => $lacking, 'target' => $target,
                    'field' => $field, 'shared' => in_array($kind, self::SHARED_KINDS, true)];
            }
        };
        $field = fn (?array $translations, string $name): array => collect($locales)
            ->mapWithKeys(fn (string $locale): array => [$locale => $translations[$locale][$name] ?? null])->all();
        $flat = fn (?array $translations): array => collect($locales)
            ->mapWithKeys(fn (string $locale): array => [$locale => $translations[$locale] ?? null])->all();

        $add('product.title', 'title', $product->title, $field($product->translations, 'title'), $product, 'title');
        $add('product.description', 'description', $product->description, $field($product->translations, 'description'), $product, 'description');
        foreach ($product->variants as $variant) {
            // The configuration every card starts with is named by the storefront in its own words.
            if ($variant->name !== ProductVariant::DEFAULT_NAME) {
                $add("variant.{$variant->id}.name", 'configuration', $variant->name, $field($variant->translations, 'name'), $variant, 'name');
            }
            $add("variant.{$variant->id}.color", 'color', $variant->color, $field($variant->translations, 'color'), $variant, 'color');
        }

        /** @var Collection<int, AttributeValue> $values */
        $values = $product->attributes->concat($product->variants->flatMap->attributes);
        foreach ($values as $value) {
            $add("value.{$value->id}", 'spec_value', self::valueText($value), $flat($value->translations), $value);
            $definition = $value->definition;
            if ($definition === null) {
                continue;
            }
            // A definition keeps its English name among its translations; its label may be Russian.
            $add("label.{$definition->id}", 'spec_name', ($definition->translations ?? [])[CatalogLocales::CONTENT_SOURCE] ?? $definition->label,
                $flat($definition->translations), $definition);
            if ($definition->group !== null) {
                $add("group.{$definition->group->id}", 'group_name', $definition->group->name, $flat($definition->group->translations), $definition->group);
            }
        }
        foreach ($this->categoryTrail($product, $categories) as $category) {
            $add("category.{$category->id}", 'category_name', $category->name,
                $category->translations->pluck('name', 'locale')->all(), $category);
        }
        if ($product->brand instanceof Brand) {
            $add("country.{$product->brand->id}", 'country_name', $product->brand->country, $flat($product->brand->country_translations), $product->brand);
        }

        return $items;
    }

    /** Whether any text of the product a buyer can read lacks a shop language. */
    public function lacks(Product $product): bool
    {
        return $this->missing($product) !== [];
    }

    /**
     * Products with any text a buyer can read lacking a shop language, oldest first.
     *
     * @return list<int>
     */
    public function pendingProductIds(): array
    {
        $locales = self::locales();
        if ($locales === []) {
            return [];
        }
        $categories = Category::query()->with('translations')->get()->keyBy('id');

        return Product::query()->with(['variants.attributes.definition.group', 'attributes.definition.group', 'brand'])->orderBy('id')->get()
            ->filter(fn (Product $product): bool => $this->missing($product, $locales, $categories) !== [])
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * One line for the operator when the product still waits for a translation, or null: the
     * translation is asked by itself (at approval, by the scheduler within ten minutes) and
     * appears on the site when it is made; until then the site shows the text it has.
     */
    public function pendingNote(Product $product): ?string
    {
        $locales = collect($this->missing($product))->pluck('locales')->flatten()->unique()
            ->sortBy(fn (string $locale): int => (int) array_search($locale, self::locales(), true))->values();
        if ($locales->isEmpty()) {
            return null;
        }
        $names = ['cs' => 'чешский', 'uk' => 'украинский', 'ru' => 'русский', 'en' => 'английский'];

        return '🌐 Перевод на '.$locales->map(fn (string $locale): string => $names[$locale] ?? $locale)->join(', ', ' и ')
            .' готовится и появится на сайте сам.';
    }

    /**
     * What the product lacks, in the operator's words: «украинский: описание, 2 значения характеристик».
     */
    public function describe(Product $product): string
    {
        $words = ['title' => 'название', 'description' => 'описание', 'configuration' => 'название конфигурации', 'color' => 'цвет',
            'spec_value' => 'значения характеристик', 'spec_name' => 'названия характеристик', 'group_name' => 'названия групп',
            'category_name' => 'название категории', 'country_name' => 'страна бренда'];
        $languages = ['cs' => 'чешский', 'uk' => 'украинский', 'ru' => 'русский', 'en' => 'английский'];
        $byLocale = [];
        foreach ($this->missing($product) as $item) {
            foreach ($item['locales'] as $locale) {
                $byLocale[$locale][$item['kind']] = ($byLocale[$locale][$item['kind']] ?? 0) + 1;
            }
        }

        return collect($byLocale)->map(fn (array $kinds, string $locale): string => ($languages[$locale] ?? $locale).': '
            .collect($kinds)->map(fn (int $count, string $kind): string => ($count > 1 ? $count.' ' : '').($words[$kind] ?? $kind))->implode(', '))
            ->implode('; ');
    }

    /**
     * The text of a characteristic's value a buyer reads. A bare number is shown with its unit,
     * and a unit in Cyrillic («ГБ», «шт») is a word of a language: it is translated with its number.
     */
    public static function valueText(AttributeValue $value): string
    {
        $text = trim((string) $value->value);
        $unit = trim((string) ($value->unit ?: $value->definition?->default_unit));

        return $unit !== '' && preg_match('/^[\d\s.,]+$/', $text) === 1 && preg_match('/\p{Cyrillic}/u', $unit) === 1
            ? $text.' '.$unit : $text;
    }

    /**
     * The product's category and the ones above it: the path and the tiles show their names.
     *
     * @param  Collection<int, Category>|null  $categories
     * @return list<Category>
     */
    private function categoryTrail(Product $product, ?Collection $categories): array
    {
        $trail = [];
        $find = fn (?int $id): ?Category => $id === null ? null
            : ($categories !== null ? $categories->get($id) : Category::query()->with('translations')->find($id));
        for ($category = $find($product->category_id), $depth = 0; $category !== null && $depth < 8; $depth++) {
            $trail[] = $category;
            $category = $category->parent_id === null ? null : $find((int) $category->parent_id);
        }

        return $trail;
    }
}
