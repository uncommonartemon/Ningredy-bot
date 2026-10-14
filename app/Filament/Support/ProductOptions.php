<?php

namespace App\Filament\Support;

/** Shared, locale-aware labels for catalog forms, tables and detail pages. */
final class ProductOptions
{
    public static function types(): array
    {
        return [
            'laptop' => __('Ноутбук'), 'desktop' => __('Готовый ПК'),
            'component' => __('Комплектующая'), 'other' => __('Другая техника'),
        ];
    }

    public static function statuses(): array
    {
        return [
            'published' => __('Опубликован'), 'draft' => __('Черновик'), 'archived' => __('Архив'),
        ];
    }

    public static function stockStatuses(): array
    {
        return [
            'unknown' => __('Уточнить'), 'in_stock' => __('В наличии'),
            'out_of_stock' => __('Нет'), 'preorder' => __('Предзаказ'),
        ];
    }
}
