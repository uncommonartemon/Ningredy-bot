<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductPhotoManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteProductPhotos implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Permanently delete specific photos from an already-published product, by their 1-based '
            .'display position (e.g. "delete the 4th photo" -> positions: [4]). Remaining photos are '
            .'renumbered and a new primary is picked automatically. Does not search for replacements - '
            .'use RefindProductPhotos for that.';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $positions = $request->array('positions');
        $variantId = $request->integer('variant_id') ?: null;

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'delete_product_photos',
            ['product_id' => $productId, 'positions' => $positions, 'variant_id' => $variantId],
            function () use ($productId, $positions, $variantId): array {
                $product = Product::query()->lockForUpdate()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');
                $deleted = app(ProductPhotoManager::class)->delete($product, $positions, $this->update, $variantId);

                return ['ok' => true, 'product_id' => $product->id, 'deleted' => $deleted];
            },
            Product::class,
            $productId,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'variant_id' => $schema->integer()->description('Selected configuration; otherwise use the last displayed gallery.'),
            'positions' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
