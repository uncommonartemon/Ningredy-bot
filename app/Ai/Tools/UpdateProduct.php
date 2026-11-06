<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Jobs\TranslateProductText;
use App\Models\Product;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateProduct implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Update safe editable product fields: title, model, description, featured state. Use SetProductActive for visibility.';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $changes = array_filter($request->all(['title', 'model', 'description', 'is_featured']), fn ($value) => $value !== null);

        if ($changes === []) {
            return $this->json(['ok' => false, 'error' => 'No editable fields supplied.']);
        }

        foreach (['title', 'model', 'description'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = trim((string) $changes[$field]);
            }
        }

        $textChanged = false;
        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'update_product',
            ['product_id' => $productId, 'changes' => $changes],
            function () use ($productId, $changes, &$textChanged): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');
                $product->update($changes);
                $textChanged = $product->wasChanged(['title', 'description']);

                return ['product_id' => $product->id, 'title' => $product->title, 'changed' => array_keys($changes)];
            },
            Product::class,
            $productId,
        );
        // The storefront reads the translated text: the old one is gone with the change,
        // and the card's other languages are made again from the English, off this request.
        if ($textChanged) {
            TranslateProductText::dispatch($productId);
        }

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'model' => $schema->string(),
            'description' => $schema->string(),
            'is_featured' => $schema->boolean(),
        ];
    }
}
