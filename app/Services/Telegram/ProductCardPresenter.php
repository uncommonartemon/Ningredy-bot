<?php

namespace App\Services\Telegram;

use App\Models\AppSetting;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductCardPresenter
{
    /**
     * @param  array<int, int>  $productIds
     */
    public function sendMany(TelegramClient $telegram, string $chatId, array $productIds): void
    {
        $productIds = array_slice($productIds, 0, 10);
        $products = Product::query()
            ->with(['brand:id,name', 'defaultVariant'])
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(fn (Product $product): int => (int) array_search($product->id, $productIds, true));

        foreach ($products as $product) {
            try {
                $this->send($telegram, $chatId, $product);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  array<int, array<int, array<string, string>>>|null  $replyMarkup
     */
    public function send(
        TelegramClient $telegram,
        string $chatId,
        Product $product,
        ?string $prefixLine = null,
        ?array $replyMarkup = null,
    ): void {
        $caption = $this->caption($product, $prefixLine);
        $paths = $product->media()
            ->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($item): bool => filled($item->disk) && filled($item->path))
            ->map(fn ($item): string => Storage::disk($item->disk)->path($item->path))
            ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
            ->take(10)
            ->values()
            ->all();

        if ($paths !== []) {
            $telegram->sendMediaGroupFiles($chatId, $paths, $caption);

            if ($replyMarkup !== null) {
                $telegram->sendMessage($chatId, '📋 Выберите действие:', $replyMarkup);
            }
        } else {
            $telegram->sendMessage($chatId, $caption, $replyMarkup);
        }
    }

    public function caption(Product $product, ?string $prefixLine = null): string
    {
        $product->loadMissing([
            'brand', 'defaultVariant.attributes.definition', 'attributes.definition', 'sources',
        ]);
        $variant = $product->defaultVariant;
        $identity = collect([$product->brand?->name, $product->model])->filter()->unique()->implode(' · ');
        $price = $variant?->price !== null
            ? trim(number_format((float) $variant->price, 0, '.', ' ').' '.(string) ($variant->currency ?? ''))
            : null;
        $stock = match ($variant?->stock_status) {
            'in_stock' => 'В наличии',
            'out_of_stock' => 'Нет в наличии',
            'preorder' => 'Предзаказ',
            default => null,
        };
        $publicUrl = rtrim((string) AppSetting::valueFor(
            AppSetting::TELEGRAM_PROXY_URL,
            (string) config('services.telegram.proxy_url'),
        ), '/');
        $link = $publicUrl !== '' ? "{$publicUrl}/products/{$product->slug}" : null;

        $header = implode("\n", array_filter([
            $prefixLine,
            "#{$product->id} · {$product->title}",
            $identity !== '' && $identity !== $product->title ? $identity : null,
            implode(' · ', array_filter([$price, $stock])) ?: null,
        ]));

        $specs = collect($product->attributes)
            ->merge($variant?->attributes ?? [])
            ->filter(fn ($attribute): bool => $attribute->definition !== null && filled($attribute->value))
            ->map(function ($attribute): string {
                $emoji = SpecificationEmoji::for($attribute->definition->key ?? $attribute->definition->label);
                // The value often already spells out its own unit (e.g. "16 GB
                // DDR5 (2 x 8 GB)", "180 Hz") - appending the separate $unit
                // column unconditionally then doubles it ("... GB GB").
                $unitAlreadyInValue = $attribute->unit
                    && str_contains(mb_strtolower((string) $attribute->value), mb_strtolower((string) $attribute->unit));
                $unitSuffix = $attribute->unit && ! $unitAlreadyInValue ? " {$attribute->unit}" : '';

                return "{$emoji} {$attribute->definition->label}: {$attribute->value}{$unitSuffix}";
            })
            ->implode("\n");
        $sources = $product->sources->pluck('url')->filter()->take(3)
            ->map(fn (string $url): string => "• {$url}")->implode("\n");
        // Telegram caps photo/album captions at 1024 chars (vs 4096 for plain
        // text messages), so specs/sources are clipped to whatever fits after
        // the header and link.
        $body = implode("\n\n", array_filter([
            $specs !== '' ? "Характеристики:\n{$specs}" : null,
            $sources !== '' ? "Источники:\n{$sources}" : null,
        ]));
        $available = max(0, 1024 - mb_strlen($header."\n\n".($link ?? '')) - 8);

        return mb_substr(implode("\n\n", array_filter([
            $header,
            mb_substr($body, 0, $available),
            $link,
        ])), 0, 1024);
    }
}
