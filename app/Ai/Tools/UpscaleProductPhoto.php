<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Products\CatalogPhotoSelection;
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
        $variantId = $request->integer('variant_id') ?: null;

        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'upscale_product_photo',
            ['product_id' => $productId, 'position' => $position, 'variant_id' => $variantId],
            function (callable $commit) use ($productId, $position, $variantId): array {
                $product = Product::query()->find($productId);
                throw_if(! $product, ModelNotFoundException::class, 'Product not found.');

                $selection = app(CatalogPhotoSelection::class);
                [$variant, $gallery, $fingerprint] = $selection->forAction($product, $this->update, $variantId);
                $media = $gallery->get($position - 1);
                throw_if(! $media, RuntimeException::class, "No photo at position {$position}.");
                throw_if(! $media->disk || ! $media->path, RuntimeException::class, 'That photo has no stored file to enhance.');

                return ($this->enhancer ?? app(ProductPhotoEnhancer::class))->enhance(
                    $media,
                    $this->update->id,
                    $selection->verificationDraft($product, $variant),
                    function () use ($selection, $product, $variant, $fingerprint): void {
                        $selection->assertCurrent($product, $variant, $fingerprint, $this->update->id);
                    },
                    function (callable $save) use ($commit, $selection, $product, $variant, $fingerprint): array {
                        return $commit(function () use ($save, $selection, $product, $variant, $fingerprint): array {
                            Product::query()->lockForUpdate()->findOrFail($product->id);
                            $product->variants()->lockForUpdate()->findOrFail($variant->id);
                            $selection->assertCurrent($product, $variant, $fingerprint, $this->update->id);

                            return ['product_id' => $product->id, ...$save()];
                        });
                    },
                );
            },
            Product::class,
            $productId,
            staged: true,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'variant_id' => $schema->integer(),
            'position' => $schema->integer()->required(),
        ];
    }
}
