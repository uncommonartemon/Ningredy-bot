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
use RuntimeException;
use Stringable;

class ReorderProductPhotos implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Reorder an already-published product\'s photos, or set a different one as primary (first). '
            .'Positions are 1-based, in the order the admin currently sees them (e.g. "swap 1 and 3" on a '
            .'4-photo product -> new_order: [3,2,1,4]). Does not search for new photos.';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $newOrder = $request->array('new_order');

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'reorder_product_photos',
            ['product_id' => $productId, 'new_order' => $newOrder],
            function () use ($productId, $newOrder): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');

                try {
                    app(ProductPhotoManager::class)->reorder($product, $newOrder);
                } catch (RuntimeException $exception) {
                    return ['ok' => false, 'error' => $exception->getMessage()];
                }

                return ['ok' => true, 'product_id' => $product->id, 'photo_count' => count($newOrder)];
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
            'new_order' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
