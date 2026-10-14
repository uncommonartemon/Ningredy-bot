<?php

namespace App\Services\Products;

final class ProductSourceTypes
{
    public const VALUES = ['manufacturer', 'retailer', 'marketplace', 'review', 'database', 'web'];

    public static function options(): array
    {
        return [
            'manufacturer' => __('Производитель'),
            'retailer' => __('Магазин'),
            'marketplace' => __('Маркетплейс'),
            'review' => __('Обзор'),
            'database' => __('База товаров'),
            'web' => __('Другой сайт'),
        ];
    }
}
