<?php

namespace App\Ai\Tools;

use App\Models\AiOperation;
use App\Models\Product;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PrepareProductDeletion implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Prepare permanent deletion of one exact catalog product. This never deletes immediately: it creates an audited pending operation that requires a Telegram confirmation button.';
    }

    public function handle(Request $request): Stringable|string
    {
        $product = Product::query()
            ->with('brand:id,name')
            ->withCount(['variants', 'media'])
            ->find($request->integer('product_id'));

        if (! $product) {
            return $this->json(['ok' => false, 'error' => 'Product not found.']);
        }

        $key = hash('sha256', implode('|', [$this->update->id, 'delete_product', $product->id]));
        $operation = AiOperation::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'telegram_update_id' => $this->update->id,
                'telegram_user_id' => $this->update->telegram_user_id,
                'tool' => class_basename(self::class),
                'action' => 'delete_product',
                'target_type' => Product::class,
                'target_id' => $product->id,
                'status' => 'awaiting_confirmation',
                'payload' => [
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'brand' => $product->brand?->name,
                    'variants_count' => $product->variants_count,
                    'media_count' => $product->media_count,
                ],
            ],
        );

        if ($operation->status !== 'awaiting_confirmation') {
            return $this->json([
                'ok' => false,
                'already_processed' => true,
                'operation_id' => $operation->id,
                'status' => $operation->status,
            ]);
        }

        return $this->json([
            'ok' => true,
            'confirmation_required' => true,
            'operation_id' => $operation->id,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'brand' => $product->brand?->name,
                'active' => $product->is_active,
                'variants_count' => $product->variants_count,
                'media_count' => $product->media_count,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['product_id' => $schema->integer()->required()];
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
