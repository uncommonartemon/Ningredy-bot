<?php

namespace App\Services\Telegram;

use App\Models\AppSetting;
use App\Models\Product;
use App\Services\Catalog\AttributePresentation;
use App\Services\Products\CatalogPhotoSelection;
use App\Services\Products\ProductCondition;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductCardPresenter
{
    /**
     * @param  array<int, int>  $productIds
     */
    public function sendMany(TelegramClient $telegram, string $chatId, array $productIds, bool $withManagementLinks = false, ?string $telegramUserId = null): void
    {
        $productIds = array_slice($productIds, 0, 10);
        $products = Product::query()
            ->with(['brand:id,name', 'defaultVariant'])
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(fn (Product $product): int => (int) array_search($product->id, $productIds, true));

        foreach ($products as $product) {
            try {
                $this->send($telegram, $chatId, $product,
                    replyMarkup: $withManagementLinks ? app(CatalogTelegramNavigation::class)->product($product) : null,
                    telegramUserId: $telegramUserId);
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
        ?string $condition = null,
        ?int $variantId = null,
        ?string $telegramUserId = null,
    ): void {
        $caption = $this->caption($product, $prefixLine, $condition, $variantId);
        $variantId = $product->variants()->available()
            ->when($condition !== null, fn ($query) => $query->where('condition', $condition))
            ->when($variantId !== null, fn ($query) => $query->whereKey($variantId))
            ->orderByDesc('is_default')->orderBy('id')->value('id');
        $hasVariantImages = $variantId !== null && $product->media()->where('product_variant_id', $variantId)
            ->where('type', 'image')->whereIn('verification_status', ['verified', 'source_verified', 'manual'])->exists();
        $shown = $product->media()
            ->when($hasVariantImages, fn ($query) => $query->where('product_variant_id', $variantId), fn ($query) => $query->whereNull('product_variant_id'))
            ->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn ($item): bool => filled($item->disk) && filled($item->path))
            ->filter(fn ($item): bool => is_file(Storage::disk($item->disk)->path($item->path)) && is_readable(Storage::disk($item->disk)->path($item->path)))
            ->take(10)
            ->values();
        $paths = $shown->map(fn ($item): string => Storage::disk($item->disk)->path($item->path))->all();

        if ($paths !== []) {
            $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
            if ($variantId !== null) {
                app(CatalogPhotoSelection::class)->remember($chatId, $product,
                    $product->variants()->findOrFail($variantId), $shown, $telegramUserId);
            }

            if ($replyMarkup !== null) {
                $telegram->sendMessage($chatId, '📋 Выберите действие:', $replyMarkup);
            }
        } else {
            $telegram->sendMessage($chatId, $caption, $replyMarkup);
        }
    }

    public function caption(Product $product, ?string $prefixLine = null, ?string $condition = null, ?int $variantId = null): string
    {
        $product->loadMissing([
            'brand', 'attributes.definition.group', 'sources',
        ]);
        $variant = $product->variants()->available()->with('attributes.definition.group')
            ->when($condition !== null, fn ($query) => $query->where('condition', $condition))
            ->when($variantId !== null, fn ($query) => $query->whereKey($variantId))
            ->orderByDesc('is_default')->orderBy('id')->first();
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
            $variant ? '📦 '.(ProductCondition::options()[$variant->condition] ?? $variant->condition) : null,
            implode(' · ', array_filter([$price, $stock])) ?: null,
        ]));

        $attributes = collect($product->attributes)->whereNull('product_variant_id')
            ->merge($variant?->attributes ?? [])
            ->keyBy('attribute_definition_id')
            ->filter(fn ($attribute): bool => $attribute->definition !== null && filled($attribute->value));
        $specs = implode("\n", AttributePresentation::lines(collect(AttributePresentation::values($attributes)),
            function (array $attribute): string {
                $emoji = SpecificationEmoji::for($attribute['key'] ?? $attribute['label']);
                // The value often already spells out its own unit (e.g. "16 GB
                // DDR5 (2 x 8 GB)", "180 Hz") - appending the separate $unit
                // column unconditionally then doubles it ("... GB GB").
                $unitAlreadyInValue = $attribute['unit']
                    && str_contains(mb_strtolower((string) $attribute['value']), mb_strtolower((string) $attribute['unit']));
                $unitSuffix = $attribute['unit'] && ! $unitAlreadyInValue ? ' '.$attribute['unit'] : '';

                return "{$emoji} {$attribute['label']}: {$attribute['value']}{$unitSuffix}";
            }));
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
