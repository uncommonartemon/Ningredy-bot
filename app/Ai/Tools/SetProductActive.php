<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\Product;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetProductActive implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Activate or deactivate a catalog product. Deactivated products are hidden from the public Inertia/Vue catalog.';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $active = $request->boolean('active');

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            $active ? 'activate_product' : 'deactivate_product',
            ['product_id' => $productId, 'active' => $active],
            function () use ($productId, $active): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');
                $product->update(['is_active' => $active]);

                return ['product_id' => $product->id, 'title' => $product->title, 'active' => $product->is_active];
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
            'active' => $schema->boolean()->required(),
        ];
    }
}
