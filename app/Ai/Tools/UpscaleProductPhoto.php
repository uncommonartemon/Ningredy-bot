<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductPhotoEnhancer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class UpscaleProductPhoto implements Tool
{
    use RecordsOperations;

    public function __construct(
        private readonly TelegramUpdate $update,
        private readonly ?ProductPhotoEnhancer $enhancer = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'AI-enhance one already-published product photo in place (sharpen/clean up), by its '
            .'1-based gallery position. Use only when the admin says a specific photo looks bad/low-quality '
            .'and asks to improve it - this is generative, not a lossless upscale, so it is never run '
            .'automatically or on every photo.';
    }

    public function handle(Request $request): Stringable|string
    {
        $productId = $request->integer('product_id');
        $position = $request->integer('position');

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'upscale_product_photo',
            ['product_id' => $productId, 'position' => $position],
            function () use ($productId, $position): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');

                $media = $product->media()->orderBy('sort_order')->get()->get($position - 1);
                throw_if(! $media, RuntimeException::class, "No photo at position {$position}.");
                throw_if(! $media->disk || ! $media->path, RuntimeException::class, 'That photo has no stored file to enhance.');

                return [
                    'product_id' => $productId,
                    ...($this->enhancer ?? app(ProductPhotoEnhancer::class))->enhance(
                        $media,
                        $this->update->id,
                    ),
                ];
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
            'position' => $schema->integer()->required(),
        ];
    }
}
