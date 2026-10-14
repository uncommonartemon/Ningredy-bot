<?php

namespace App\Services\Products;

final class ProductCondition
{
    public const VALUES = ['new', 'open_box', 'used', 'refurbished'];

    public static function options(): array
    {
        return ['new' => __('Новый'), 'open_box' => __('Распакованный'), 'used' => __('Б/у'), 'refurbished' => __('Восстановленный')];
    }
}
