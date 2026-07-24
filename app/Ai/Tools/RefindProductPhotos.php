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

class RefindProductPhotos implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Search the web again for a published product\'s photos. Runs in the background - the '
            .'result (new photos, or "not found") arrives as a separate Telegram message once ready, so '
            .'never claim the new photos are already saved. Three uses: leave replace_positions empty and '
            .'fresh=false to just fill the gallery up to its normal target (e.g. "find a third photo"); '
            .'set replace_positions to specific 1-based positions to swap only those out for new ones '
            .'(e.g. "replace photos 1 and 3" -> [1,3]); set fresh=true to wipe every current photo and '
            .'search from scratch ("find all photos again").';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $replacePositions = $request->array('replace_positions');
        $fresh = $request->boolean('fresh');

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'refind_product_photos',
            ['product_id' => $productId, 'replace_positions' => $replacePositions, 'fresh' => $fresh],
            function () use ($productId, $replacePositions, $fresh): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');
                $started = app(ProductPhotoManager::class)->refind($product, $replacePositions, $fresh);

                return $started
                    ? ['ok' => true, 'product_id' => $product->id, 'status' => 'search_started']
                    : ['ok' => false, 'product_id' => $product->id, 'error' => 'No source draft or variant found for this product.'];
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
            'replace_positions' => $schema->array()->items($schema->integer())->required(),
            'fresh' => $schema->boolean()->required(),
        ];
    }
}
