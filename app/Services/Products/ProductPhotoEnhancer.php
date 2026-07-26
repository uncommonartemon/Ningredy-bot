<?php

namespace App\Services\Products;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use App\Services\Ai\AiSettings;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Image;
use RuntimeException;
use Throwable;

class ProductPhotoEnhancer
{
    private const PROMPT = <<<'PROMPT'
        Enhance this exact product photo: increase sharpness, resolution and clarity,
        remove noise/compression artifacts and improve lighting balance. This must
        remain a faithful representation of the exact same physical product - do not
        add, remove, invent, or alter any feature, text, logo, color, proportion, or
        detail that is not already visible in the source image. If in doubt, change
        less. Keep the same framing and background.
        PROMPT;

    public function __construct(
        private readonly ProductImageEncoder $encoder,
        private readonly ProductImageVisionVerifier $vision,
    ) {}

    /**
     * @return array{ok: true, media_id: int, width: int, height: int}
     */
    public function enhance(
        ProductMedia|ProductDraftMedia $media,
        ?int $telegramUpdateId = null,
        ?ProductDraft $verificationDraft = null,
    ): array {
        if (! $media->disk || ! $media->path || ! Storage::disk($media->disk)->exists($media->path)) {
            throw new RuntimeException('That photo has no readable stored file to enhance.');
        }

        $provider = app(AiSettings::class)->providerFor('image_upscale');
        $model = app(AiSettings::class)->modelFor('image_upscale');
        $run = AiRun::query()->create([
            'telegram_update_id' => $telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => self::PROMPT,
            'started_at' => now(),
        ]);
        $image = null;

        try {
            $response = Image::of(self::PROMPT)
                ->attachments([new StoredImage($media->path, $media->disk)])
                ->timeout((int) config('services.image_upscale.timeout', 90))
                ->generate($provider, $model);

            $image = @imagecreatefromstring($response->firstImage()->content());
            throw_unless($image instanceof GdImage, RuntimeException::class, 'The AI did not return a usable image.');

            $verification = null;

            if ($verificationDraft) {
                $selected = $this->vision->select($verificationDraft, [[
                    'image' => $image,
                    'source_url' => $media->source_url,
                    'source_priority' => 'enhanced_reference',
                ]], 1, $telegramUpdateId);
                $verification = $selected[0] ?? null;
                throw_unless(
                    is_array($verification),
                    RuntimeException::class,
                    'The enhanced photo did not pass the model and color verification. The original was kept.',
                );
            }

            $converted = $this->encoder->toWebp($image);
            throw_unless(
                Storage::disk($media->disk)->put($media->path, $converted['bytes']),
                RuntimeException::class,
                'Could not save the enhanced photo.',
            );

            $media->update([
                'width' => $converted['width'],
                'height' => $converted['height'],
                'file_size' => strlen($converted['bytes']),
                'checksum' => hash('sha256', $converted['bytes']),
                'verification_status' => $verification ? 'verified' : $media->verification_status,
                'verification_score' => $verification
                    ? ($verification['vision_score'] ?? 0) / 100
                    : $media->verification_score,
                'verification_model' => $verification['vision_model'] ?? $media->verification_model,
                'verification_notes' => trim(implode(' ', array_filter([
                    $verification['vision_reason'] ?? $media->verification_notes,
                    $telegramUpdateId
                        ? "[AI-enhanced by Telegram update {$telegramUpdateId}]"
                        : '[AI-enhanced by admin request]',
                ]))),
            ]);

            $run->update([
                'status' => 'completed',
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);

            return [
                'ok' => true,
                'media_id' => $media->id,
                'width' => $converted['width'],
                'height' => $converted['height'],
            ];
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);

            throw $exception;
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }
}
