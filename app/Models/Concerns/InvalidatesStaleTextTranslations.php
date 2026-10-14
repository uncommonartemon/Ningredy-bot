<?php

namespace App\Models\Concerns;

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
    }
}
