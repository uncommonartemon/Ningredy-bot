<?php

namespace App\Services\Products;

use App\Ai\Agents\CatalogTranslationAgent;
use App\Models\AiRun;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Ai\AiSettings;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Fills the translations a catalog product is missing in the languages the admin
 * chose (operator, 2026-11-01): products added before a language was switched on,
 * and products added by hand with one translation only. The English text is the
 * source; nothing that already has a translation is touched.
 */
final class CatalogTextTranslator
{
    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Products with any enabled language missing from their own text or their characteristics.
     *
     * @return array<int, int>
     */
    public function pendingProductIds(): array
    {
        $locales = array_keys(CatalogLocales::contentTranslations());
        if ($locales === []) {
            return [];
        }

        return Product::query()->with(['variants.attributes.definition', 'attributes.definition'])->orderBy('id')->get()
            ->filter(fn (Product $product): bool => $this->items($product, $locales) !== []
                || $product->variants->contains(fn ($variant): bool => collect($locales)->contains(fn (string $locale): bool => collect(['title', 'description'])
                    ->contains(fn (string $field): bool => filled($product->translations[$locale][$field] ?? null) && ! filled($variant->translations[$locale][$field] ?? null)))))
            ->pluck('id')->values()->all();
    }

    /** How many texts were filled in. */
    public function translate(Product $product): int
    {
        $languages = CatalogLocales::contentTranslations();
        $product->loadMissing(['variants.attributes.definition', 'attributes.definition']);
        $items = $this->items($product, array_keys($languages));
        if ($items === []) {
            return $this->mirrorIntoVariants($product, array_keys($languages));
        }
        $run = AiRun::query()->create([
            'provider' => $this->settings->providerFor('server_assistant'), 'model' => $this->settings->modelFor('server_assistant'),
            'status' => 'running', 'prompt' => mb_substr(json_encode($items, JSON_UNESCAPED_UNICODE), 0, 60000), 'started_at' => now(),
            'activity' => ['stage' => 'catalog_translation', 'product_id' => $product->id],
        ]);
        try {
            $response = (new CatalogTranslationAgent($languages))->prompt(
                json_encode(['items' => array_values(array_map(fn (array $item): array => array_intersect_key($item, array_flip(['id', 'kind', 'text', 'locales'])), $items))],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                provider: $run->provider, model: $run->model, timeout: 180);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);

            throw $exception;
        }
        $run->update(['status' => 'completed', 'usage' => $response->usage->toArray(), 'invocation_id' => $response->invocationId,
            'response' => $response->toArray(), 'completed_at' => now()]);

        $translated = [];
        foreach ((array) ($response->toArray()['translations'] ?? []) as $entry) {
            $locale = (string) ($entry['locale'] ?? '');
            $id = (string) ($entry['id'] ?? '');
            $text = trim((string) ($entry['text'] ?? ''));
            if (isset($items[$id]) && in_array($locale, $items[$id]['locales'], true) && $text !== '') {
                $translated[$id][$locale] = $text;
            }
        }

        return $this->write($product, $items, $translated) + $this->mirrorIntoVariants($product->fresh(['variants']), array_keys($languages));
    }

    /**
     * A variant's translations are the product's own text for that variant (the draft
     * workflow copies the card's translations there; the storefront shows them over the
     * product's). A variant missing a language takes the product's text in it - never a
     * translation of its configuration name (live 2026-11-01: «Base configuration»
     * translated into the title made every Ukrainian catalog card read «Базова конфігурація»).
     *
     * @param  array<int, string>  $locales
     */
    private function mirrorIntoVariants(Product $product, array $locales): int
    {
        $filled = 0;
        foreach ($product->variants as $variant) {
            $translations = $variant->translations ?? [];
            foreach ($locales as $locale) {
                foreach (['title', 'description'] as $field) {
                    $text = $product->translations[$locale][$field] ?? null;
                    if (filled($text) && ! filled($translations[$locale][$field] ?? null)) {
                        $translations[$locale][$field] = $text;
                        $filled++;
                    }
                }
            }
            if ($translations !== ($variant->translations ?? [])) {
                $variant->update(['translations' => $translations]);
            }
        }

        return $filled;
    }

    /**
     * Every English text of the product that lacks a translation in one of the locales,
     * keyed by a stable id, with the locales it lacks.
     *
     * @param  array<int, string>  $locales
     * @return array<string, array{id: string, kind: string, text: string, locales: array<int, string>, target: mixed}>
     */
    private function items(Product $product, array $locales): array
    {
        $items = [];
        $add = function (string $id, string $kind, ?string $text, array $missing, mixed $target) use (&$items): void {
            $text = trim((string) $text);
            // A number with its unit ("16 GB", "2.8K") reads the same in every language: only a
            // text with a word of three letters or more is sent.
            if ($text !== '' && $missing !== [] && preg_match('/\p{L}{3,}/u', $text) === 1) {
                $items[$id] = ['id' => $id, 'kind' => $kind, 'text' => $text, 'locales' => array_values($missing), 'target' => $target];
            }
        };
        $missingIn = fn (?array $translations, string $field): array => array_values(array_filter($locales,
            fn (string $locale): bool => ! filled($translations[$locale][$field] ?? null)));
        $add('product.title', 'title', $product->title, $missingIn($product->translations, 'title'), $product);
        $add('product.description', 'description', $product->description, $missingIn($product->translations, 'description'), $product);

        /** @var Collection<int, AttributeValue> $values */
        $values = $product->attributes->concat($product->variants->flatMap->attributes);
        foreach ($values as $value) {
            $flat = fn (?array $translations): array => array_values(array_filter($locales,
                fn (string $locale): bool => ! (is_string($translations[$locale] ?? null) && trim($translations[$locale]) !== '')));
            $add("value.{$value->id}", 'spec_value', $value->value, $flat($value->translations), $value);
            if ($value->definition !== null) {
                // A definition keeps its English name among its translations; its label may be Russian.
                $add("label.{$value->definition->id}", 'spec_name', ($value->definition->translations ?? [])[CatalogLocales::CONTENT_SOURCE] ?? $value->definition->label,
                    $flat($value->definition->translations), $value->definition);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, array{id: string, kind: string, text: string, locales: array<int, string>, target: mixed}>  $items
     * @param  array<string, array<string, string>>  $translated
     */
    private function write(Product $product, array $items, array $translated): int
    {
        $filled = 0;
        $productTranslations = $product->translations ?? [];
        foreach (['title', 'description'] as $field) {
            foreach ($translated["product.{$field}"] ?? [] as $locale => $text) {
                $productTranslations[$locale][$field] = $text;
                $filled++;
            }
        }
        if ($productTranslations !== ($product->translations ?? [])) {
            $product->update(['translations' => $productTranslations]);
        }

        foreach ($items as $id => $item) {
            if (! str_starts_with($id, 'value.') && ! str_starts_with($id, 'label.')) {
                continue;
            }
            $target = $item['target'];
            $current = $target->fresh()?->translations ?? [];
            $added = array_diff_key($translated[$id] ?? [], array_filter($current, fn (mixed $text): bool => is_string($text) && trim($text) !== ''));
            if ($added !== []) {
                $target->update(['translations' => [...$current, ...$added]]);
                $filled += count($added);
            }
        }

        return $filled;
    }
}
