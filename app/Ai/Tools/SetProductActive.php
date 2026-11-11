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
        return 'Take a catalog product off sale (active=false) or put it back on sale (active=true). Off sale the product sleeps: '
            .'the public catalog no longer shows it, while its card, photos, characteristics and the books stay, because the same '
            .'product comes in again. This is not a deletion and needs no confirmation.';
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
                $active ? $product->putBackOnSale() : $product->takeOffSale();

                return ['product_id' => $product->id, 'title' => $product->title, 'active' => $product->is_active,
                    'on_sale' => $product->isOnSale(), 'in_stock' => (int) $product->variants()->sum('quantity')];
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
