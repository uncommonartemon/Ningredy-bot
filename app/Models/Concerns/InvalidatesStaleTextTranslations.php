<?php

namespace App\Models\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;

trait InvalidatesStaleTextTranslations
{
    protected static function bootInvalidatesStaleTextTranslations(): void
    {
        static::saving(function (Model $record): void {
            if (! $record->exists) {
                return;
            }
            $before = $record->getOriginal('translations') ?? [];
            $translations = $record->translations ?? [];
            $fields = $record instanceof ProductVariant
                ? ['name' => 'title'] : ['title' => 'title', 'description' => 'description'];
            if (! $record->isDirty(array_keys($fields))) {
                return;
            }
            foreach ($fields as $baseField => $translatedField) {
                if (! $record->isDirty($baseField)) {
                    continue;
                }
                foreach ($translations as $locale => $values) {
                    if (is_array($values) && array_key_exists($translatedField, $values)
                        && ($before[$locale][$translatedField] ?? null) === $values[$translatedField]) {
                        unset($translations[$locale][$translatedField]);
                    }
                }
            }
            $record->translations = $translations;
        });

        // The storefront shows a variant's translated text over the product's, and that
        // text is a copy of the product's: a stale copy would outlive the product's own.
        static::saved(function (Model $record): void {
            if (! $record instanceof Product || ! $record->wasChanged(['title', 'description'])) {
                return;
            }
            $before = (array) ($record->getOriginal('translations') ?? []);
            foreach ($record->variants()->get() as $variant) {
                $translations = $variant->translations ?? [];
                foreach (['title', 'description'] as $field) {
                    if (! $record->wasChanged($field)) {
                        continue;
                    }
                    foreach ($translations as $locale => $values) {
                        if (is_array($values) && array_key_exists($field, $values)
                            && $values[$field] === ($before[$locale][$field] ?? null)
                            && ! filled($record->translations[$locale][$field] ?? null)) {
                            unset($translations[$locale][$field]);
                        }
                    }
                }
                if ($translations !== ($variant->translations ?? [])) {
                    $variant->update(['translations' => $translations]);
                }
            }
        });
    }
}
