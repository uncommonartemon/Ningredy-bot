<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductImageEncoder;
use GdImage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Throwable;

class UpscaleProductPhoto implements Tool
{
    use RecordsOperations;

    /**
     * Kept strict on purpose: this generates a new image from a reference,
     * it does not run a lossless super-resolution filter. Left unconstrained
     * it can invent details that are not on the real product - a real risk
     * for a storefront - so the prompt explicitly forbids that.
     */
    private const PROMPT = <<<'PROMPT'
        Enhance this exact product photo: increase sharpness, resolution and clarity,
        remove noise/compression artifacts and improve lighting balance. This must
        remain a faithful representation of the exact same physical product - do not
        add, remove, invent, or alter any feature, text, logo, color, proportion, or
        detail that is not already visible in the source image. If in doubt, change
        less. Keep the same framing and background.
        PROMPT;

    public function __construct(private readonly TelegramUpdate $update) {}

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

                return $this->upscale($media);
            },
            Product::class,
            $productId,
        );

        return $this->json($result);
    }

    private function upscale(mixed $media): array
    {
        $provider = app(AiSettings::class)->providerFor('image_upscale');
        $model = app(AiSettings::class)->modelFor('image_upscale');
        $run = AiRun::query()->create([
            'telegram_update_id' => $this->update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => self::PROMPT,
            'started_at' => now(),
        ]);

        try {
            $response = Image::of(self::PROMPT)
                ->attachments([new StoredImage($media->path, $media->disk)])
                ->timeout((int) config('services.image_upscale.timeout', 90))
                ->generate($provider, $model);

            $image = @imagecreatefromstring($response->firstImage()->content());
            throw_unless($image instanceof GdImage, RuntimeException::class, 'The AI did not return a usable image.');

            $converted = app(ProductImageEncoder::class)->toWebp($image);
            imagedestroy($image);

            Storage::disk($media->disk)->put($media->path, $converted['bytes']);
            $media->update([
                'width' => $converted['width'],
                'height' => $converted['height'],
                'file_size' => strlen($converted['bytes']),
                'checksum' => hash('sha256', $converted['bytes']),
                'verification_notes' => trim(($media->verification_notes ?? '').' [AI-enhanced by admin request]'),
            ]);

            $run->update([
                'status' => 'completed',
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            return ['ok' => true, 'product_id' => $media->product_id, 'width' => $converted['width'], 'height' => $converted['height']];
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required(),
            'position' => $schema->integer()->required(),
        ];
    }
}
