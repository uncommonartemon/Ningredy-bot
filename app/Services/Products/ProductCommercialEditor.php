<?php

namespace App\Services\Products;

use App\Filament\Support\ProductOptions;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit commercial edits of a bound variant, without rewriting the product form. */
class ProductCommercialEditor
{
    private const FIELDS = ['price', 'currency', 'stock_status', 'quantity', 'warranty_months'];

    public function values(Product $product, ?int $variantId = null): array
    {
        $variant = $variantId === null
            ? $product->variants()->orderByDesc('is_default')->orderBy('id')->first()
            : $product->variants()->whereKey($variantId)->first();

        return $variant ? [
            'variant_id' => $variant->id,
            ...$variant->only(self::FIELDS),
            'commercial_revision' => $this->revision($variant),
        ] : ['variant_id' => null, 'commercial_revision' => null];
    }

    public function save(Product $product, array $data): ProductVariant
    {
        $validated = Validator::make($data, [
            'variant_id' => 'required|integer', 'commercial_revision' => 'required|string|size:64',
            'price' => 'nullable|numeric|min:0', 'currency' => 'required|alpha|size:3',
            'stock_status' => 'required|in:'.implode(',', array_keys(ProductOptions::stockStatuses())),
            'quantity' => 'required|integer|min:0', 'warranty_months' => 'nullable|integer|min:0',
        ])->validate();

        return DB::transaction(function () use ($product, $validated): ProductVariant {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $variant = $product->variants()->lockForUpdate()->whereKey($validated['variant_id'])->first();
            if (! $variant) {
                throw ValidationException::withMessages(['variant_id' => __('catalog-quick-edit.missing_variant')]);
            }
            if (! hash_equals($this->revision($variant), $validated['commercial_revision'])) {
                throw ValidationException::withMessages(['price' => __('catalog-quick-edit.changed_variant')]);
            }
            $variant->fill([
                ...array_intersect_key($validated, array_flip(self::FIELDS)),
                'currency' => strtoupper($validated['currency']),
            ])->save();

            return $variant;
        });
    }

    private function revision(ProductVariant $variant): string
    {
        return hash('sha256', json_encode(['id' => $variant->id, 'product_id' => $variant->product_id,
            ...$variant->only(self::FIELDS)], JSON_THROW_ON_ERROR));
    }
}
