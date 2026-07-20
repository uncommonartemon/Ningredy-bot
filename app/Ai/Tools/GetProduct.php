<?php

namespace App\Ai\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetProduct implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get complete local catalog data for a product by its numeric ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $product = Product::query()->with([
            'brand', 'category', 'variants.attributes.definition', 'media', 'sources',
        ])->find($request->integer('product_id'));

        if (! $product) {
            return json_encode(['found' => false, 'message' => 'Product not found.']);
        }

        return json_encode([
            'found' => true,
            'product' => $product->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['product_id' => $schema->integer()->required()];
    }
}
