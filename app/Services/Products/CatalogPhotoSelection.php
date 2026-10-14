<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Telegram\TelegramCatalogEditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The same variant and ordering for a displayed album and its subsequent actions. */
final class CatalogPhotoSelection
{
    public function variant(Product $product, ?int $variantId = null): ProductVariant
    {
        $variant = $product->variants()->available()->when($variantId !== null, fn ($q) => $q->whereKey($variantId))
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())
            ->orderByDesc('is_default')->orderBy('id')->first();
        throw_unless($variant, RuntimeException::class, 'Комплектация отсутствует или неактивна. Откройте товар заново.');

        return $variant;
    }

    public function gallery(Product $product, ProductVariant $variant): Collection
    {
        $query = $product->media()->where('type', 'image')->whereIn('verification_status', ['verified', 'source_verified', 'manual']);
        $own = (clone $query)->where('product_variant_id', $variant->id)->exists();

        return $query->when($own, fn ($q) => $q->where('product_variant_id', $variant->id), fn ($q) => $q->whereNull('product_variant_id'))
            ->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')->get();
    }

    public function fingerprint(Product $product, ProductVariant $variant): string
    {
        return app(TelegramCatalogEditor::class)->fingerprint($product->fresh(), $variant->fresh());
    }

    public function remember(string $chat, Product $product, ProductVariant $variant, Collection $shown, ?string $user = null): void
    {
        if (str_starts_with($chat, '-') && $user === null) {
            return;
        }
        Cache::put($this->viewKey($chat, $user, $product->id), ['variant_id' => $variant->id,
            'ids' => $shown->pluck('id')->all(), 'fingerprint' => $this->fingerprint($product, $variant)], now()->addHours(2));
    }

    /** @return array{0: ProductVariant, 1: Collection, 2: string} */
    public function forAction(Product $product, ?TelegramUpdate $update = null, ?int $variantId = null): array
    {
        $viewed = $update ? Cache::get($this->viewKey($update->chat_id, $update->telegram_user_id, $product->id)) : null;
        throw_if($update && str_starts_with($update->chat_id, '-') && ! is_array($viewed) && $variantId === null,
            RuntimeException::class, 'Сначала откройте товар своей командой /product '.$product->id.' или укажите комплектацию.');
        if (is_array($viewed) && $variantId === null) {
            $variantId = $viewed['variant_id'];
        }
        $variant = $this->variant($product, $variantId);
        $fingerprint = $this->fingerprint($product, $variant);
        $media = $this->gallery($product, $variant);
        throw_if($media->contains(fn ($image) => $image->product_variant_id === null), RuntimeException::class,
            'Это общая галерея нескольких комплектаций. Сначала назначьте фотографии нужной комплектации в админке; общие фото сохранены.');
        if (is_array($viewed) && (int) $viewed['variant_id'] === $variant->id) {
            throw_unless(hash_equals($viewed['fingerprint'], $fingerprint), RuntimeException::class,
                'Товар или порядок фотографий изменились. Откройте /product '.$product->id.' заново перед изменением фото.');
            $media = collect($viewed['ids'])->map(fn ($id) => $media->firstWhere('id', $id))->filter()->values();
        }

        return [$variant, $media, $fingerprint];
    }

    private function viewKey(string $chat, ?string $user, int $productId): string
    {
        return 'catalog-gallery:'.(str_starts_with($chat, '-') ? $chat.':'.$user : $chat).':'.$productId;
    }

    public function assertCurrent(Product $product, ProductVariant $variant, string $fingerprint, ?int $updateId = null): void
    {
        app(ProductSearchCancellation::class)->throwIfCancelled($updateId);
        throw_unless($product->fresh() && $variant->fresh() && hash_equals($fingerprint, $this->fingerprint($product, $variant)),
            RuntimeException::class, 'Товар или фотографии изменились во время операции. Старые фото сохранены; откройте карточку заново.');
    }

    public function verificationDraft(Product $product, ProductVariant $variant): ProductDraft
    {
        $attributes = $product->attributes()->whereNull('product_variant_id')->with('definition')->get()
            ->merge($variant->attributes()->with('definition')->get())->keyBy('attribute_definition_id');
        $specifications = $attributes->map(fn ($a) => ['key' => $a->definition->key, 'name' => $a->definition->label,
            'value' => $a->value, 'unit' => $a->unit])->keyBy(fn ($spec) => mb_strtolower($spec['key']));
        foreach (['sku' => 'SKU', 'mpn' => 'Part number', 'gtin' => 'GTIN'] as $key => $name) {
            if (filled($variant->{$key})) {
                $specifications->put($key, ['key' => $key, 'name' => $name, 'value' => $variant->{$key}]);
            }
        }
        if (filled($variant->name)) {
            $specifications->put('variant_name', ['key' => 'variant_name', 'name' => 'Configuration', 'value' => $variant->name]);
        }

        return new ProductDraft(['title' => $product->title, 'brand' => $product->brand?->name,
            'model' => $product->model, 'product_type' => $product->product_type, 'category' => $product->category?->slug,
            'color' => $variant->color, 'condition' => $variant->condition, 'description' => $product->description,
            'specifications' => $specifications->values()->all()]);
    }
}
