<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\ProductVariant;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateVariant implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Update price, currency, stock status, quantity or color of a concrete product variant. Requires variant ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $variantId = $request->integer('variant_id');
        $changes = array_filter($request->all(['price', 'currency', 'stock_status', 'quantity', 'color']), fn ($value) => $value !== null);
        if ($changes === []) {
            return $this->json(['ok' => false, 'error' => 'No editable fields supplied.']);
        }
        if (isset($changes['price']) && (! is_numeric($changes['price']) || (float) $changes['price'] < 0)) {
            return $this->json(['ok' => false, 'error' => 'price must be a non-negative number']);
        }
        if (isset($changes['quantity']) && (! is_numeric($changes['quantity']) || (int) $changes['quantity'] < 0)) {
            return $this->json(['ok' => false, 'error' => 'quantity must be a non-negative integer']);
        }
        if (isset($changes['currency'])) {
            $changes['currency'] = mb_strtoupper((string) $changes['currency']);
            if (preg_match('/^[A-Z]{3}$/', $changes['currency']) !== 1) {
                return $this->json(['ok' => false, 'error' => 'currency must be a 3-letter ISO code']);
            }
        }
        if (isset($changes['stock_status']) && ! in_array($changes['stock_status'], ['in_stock', 'out_of_stock', 'preorder', 'unknown'], true)) {
            return $this->json(['ok' => false, 'error' => 'Invalid stock_status']);
        }

        return $this->json($this->recordOperation(
            $this->update,
            class_basename(self::class),
            'update_product_variant',
            ['variant_id' => $variantId, 'changes' => $changes],
            function () use ($variantId, $changes): array {
                $variant = ProductVariant::query()->find($variantId);
                throw_if(! $variant, ModelNotFoundException::class, 'Product variant not found.');
                $variant->update($changes);

                return ['variant_id' => $variant->id, 'product_id' => $variant->product_id, 'changed' => array_keys($changes)];
            },
            ProductVariant::class,
            $variantId,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->required(),
            'price' => $schema->number(),
            'currency' => $schema->string(),
            'stock_status' => $schema->string()->enum(['in_stock', 'out_of_stock', 'preorder', 'unknown']),
            'quantity' => $schema->integer(),
            'color' => $schema->string(),
        ];
    }
}
