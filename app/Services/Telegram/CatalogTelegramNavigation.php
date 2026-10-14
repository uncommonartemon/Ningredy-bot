<?php

namespace App\Services\Telegram;

use App\Models\AppSetting;
use App\Models\Product;

/** Free navigation to native CRUD forms; never starts AI or signs a user in. */
final class CatalogTelegramNavigation
{
    public function catalog(): ?array
    {
        $base = $this->baseUrl();
        $rows = [[['text' => '📥 Загрузить товар в Telegram', 'callback_data' => 'product:create']]];
        if ($base === null) {
            return ['inline_keyboard' => $rows];
        }

        return ['inline_keyboard' => [...$rows,
            [['text' => '➕ Создать товар вручную', 'url' => $base.'/admin/products/create']],
            [['text' => '✏️ Товары, цены и остатки', 'url' => $base.'/admin/products']],
            [['text' => '⚙️ Категории и стартовые характеристики', 'url' => $base.'/admin/categories']],
        ]];
    }

    public function product(Product $product): ?array
    {
        $base = $this->baseUrl();
        $rows = [[['text' => '✏️ Изменить в Telegram', 'callback_data' => 'product:tgedit:'.$product->id]],
            [['text' => '🗑 Удалить товар', 'callback_data' => 'product:remove:'.$product->id]]];
        if ($base !== null) {
            $rows[] = [['text' => '🛠 Открыть в Filament', 'url' => $base.'/admin/products/'.$product->getRouteKey().'/edit']];
        }

        return ['inline_keyboard' => $rows];
    }

    private function baseUrl(): ?string
    {
        $base = rtrim((string) AppSetting::valueFor(AppSetting::TELEGRAM_PROXY_URL,
            (string) config('services.telegram.proxy_url')), '/');
        $parts = parse_url($base);
        if (! filter_var($base, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $base;
    }
}
