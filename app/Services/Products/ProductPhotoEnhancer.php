<?php

namespace App\Services\Products;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionImagePreparer;
use App\Services\Vision\VisionService;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Operator's decision, 2026-11-08 in the photo menu. A
     * shop's mark lies over the picture; the part's own label is its identity and stays.
     */
    private const UNMARK_PROMPT = <<<'PROMPT'
        Remove from this exact product photo everything a shop or website laid over the picture:
        watermarks, logos, website addresses, stamps, badges and captions on top of the image. Fill
        where they were with what is plainly behind them: the product's own surface or the background.
        Everything that belongs to the product itself stays exactly as it is, including the text,
        logos, labels, stickers and markings printed on the product. This must remain a faithful photo
        of the same physical product: do not add, remove, invent or alter any part, color, proportion or
        detail of the product. Keep the same framing.
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
        ?callable $beforeSave = null,
        ?callable $commit = null,
        string $purpose = 'enhance',
    ): array {
        if (! $media->disk || ! $media->path || ! Storage::disk($media->disk)->exists($media->path)) {
            throw new RuntimeException('That photo has no readable stored file to enhance.');
        }
        $prompt = $purpose === 'unmark' ? self::UNMARK_PROMPT : self::PROMPT;
        // Only where a shop laid something over the picture gets cleaned; found before paying for a picture.
        $overlays = $purpose === 'unmark' ? $this->overlays($media, $telegramUpdateId) : [];

        $provider = app(AiSettings::class)->providerFor('image_upscale');
        $model = app(AiSettings::class)->modelFor('image_upscale');
        $run = AiRun::query()->create([
            'telegram_update_id' => $telegramUpdateId,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $prompt,
            'started_at' => now(),
        ]);
        $image = null;
        $stagedPath = null;

        try {
            $response = Image::of($prompt)
                ->attachments([new StoredImage($media->path, $media->disk)])
                ->timeout((int) config('services.image_upscale.timeout', 90))
                ->generate($provider, $model);

            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);

            if ($beforeSave) {
                $beforeSave();
            }

            $generatedBytes = $response->firstImage()->content();
            $dimensions = @getimagesizefromstring($generatedBytes);
            throw_unless($dimensions && $this->encoder->isSafeToDecode($dimensions[0], $dimensions[1]),
                RuntimeException::class, 'Generated image exceeds safe decoding limits.');
            $image = @imagecreatefromstring($generatedBytes);
            throw_unless($image instanceof GdImage, RuntimeException::class, 'The AI did not return a usable image.');
            if ($purpose === 'unmark') {
                $image = $this->onlyOverTheOverlays($media, $image, $overlays);
            }

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

            if ($beforeSave) {
                $beforeSave();
            }
            $converted = $this->encoder->toWebp($image);
            $stagedPath = dirname($media->path).'/enhanced-'.Str::uuid().'.webp';
            throw_unless(
                Storage::disk($media->disk)->put($stagedPath, $converted['bytes']),
                RuntimeException::class,
                'Could not save the enhanced photo.',
            );

            app(ProductSearchCancellation::class)->throwIfCancelled($telegramUpdateId);
            if ($beforeSave) {
                $beforeSave();
            }

            $save = function () use ($media, $stagedPath, $converted, $verification, $telegramUpdateId, $run, $response, $purpose): array {
                $media->update([
                    'path' => $stagedPath,
                    'mime_type' => 'image/webp',
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
                        ($purpose === 'unmark' ? '[Watermark removed by AI' : '[AI-enhanced')
                            .($telegramUpdateId ? " by Telegram update {$telegramUpdateId}]" : ' by admin request]'),
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
            };
            $result = $commit ? $commit($save) : DB::transaction($save);
            $stagedPath = null;

            return $result;
        } catch (Throwable $exception) {
            if ($stagedPath !== null && ! ProductMedia::where('disk', $media->disk)->where('path', $stagedPath)->exists()
                && ! ProductDraftMedia::where('disk', $media->disk)->where('path', $stagedPath)->exists()) {
                Storage::disk($media->disk)->delete($stagedPath);
            }
            if ($run->fresh()->status !== 'completed') {
                $run->update([
                    'status' => 'failed',
                    ...(isset($response) ? ['usage' => $response->usage->toArray()] : []),
                    'error' => mb_substr($exception->getMessage(), 0, 5000),
                    'completed_at' => now(),
                ]);
            }

            throw $exception;
        } finally {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * Where a shop laid its marks over the photo, as boxes in percent of the picture.
     *
     * @return list<array{left: int, top: int, right: int, bottom: int}>
     */
    private function overlays(ProductMedia|ProductDraftMedia $media, ?int $telegramUpdateId): array
    {
        $found = app(VisionService::class)->locateOverlays(
            [app(VisionImagePreparer::class)->fromBytes((string) Storage::disk($media->disk)->get($media->path), 'photo.jpg')],
            new VisionCallContext($telegramUpdateId, useSearchBudget: false),
        );
        $boxes = [];
        foreach ((array) ($found['overlays'] ?? []) as $box) {
            $box = array_map('intval', array_intersect_key((array) $box, array_flip(['left', 'top', 'right', 'bottom'])));
            if (count($box) === 4 && $box['right'] > $box['left'] && $box['bottom'] > $box['top']) {
                $boxes[] = $box;
            }
        }
        throw_if($boxes === [], RuntimeException::class, 'Водяной знак на фото не найден: фото не изменено.');
        $covered = array_sum(array_map(fn (array $box): int => ($box['right'] - $box['left']) * ($box['bottom'] - $box['top']), $boxes));
        throw_if($covered > 5000, RuntimeException::class,
            'Водяной знак закрывает больше половины фото: его не убрать, не перерисовав сам товар. Фото не изменено.');

        return $boxes;
    }

    /**
     * The cleaned picture is taken only inside the boxes; everything else, a part's label first of
     * all, stays the original pixels. Cleaned whole, a real KIOXIA frame came back with its label
     * reading KXG602NV512G instead of KXG60ZNV512G (2026-11-08).
     *
     * @param  list<array{left: int, top: int, right: int, bottom: int}>  $boxes
     */
    private function onlyOverTheOverlays(ProductMedia|ProductDraftMedia $media, GdImage $cleaned, array $boxes): GdImage
    {
        $original = @imagecreatefromstring((string) Storage::disk($media->disk)->get($media->path));
        throw_unless($original instanceof GdImage, RuntimeException::class, 'Не удалось прочитать исходное фото: оно не изменено.');
        if (! imageistruecolor($original)) {
            imagepalettetotruecolor($original);
        }
        $width = imagesx($original);
        $height = imagesy($original);
        throw_if(abs($width / $height - imagesx($cleaned) / imagesy($cleaned)) > 0.02 * $width / $height, RuntimeException::class,
            'Очищенное фото пришло другой формы, его не наложить на исходное: фото не изменено.');
        $scaled = imagescale($cleaned, $width, $height, IMG_BICUBIC);
        throw_unless($scaled instanceof GdImage, RuntimeException::class, 'Не удалось подогнать очищенное фото: исходное не изменено.');
        $padX = (int) ceil($width * 0.02);
        $padY = (int) ceil($height * 0.02);
        // The cleaned white is never quite the original white, and a hard edge shows as a pale
        // box (live 2026-11-08): across a band inside the margin the cleaned picture fades in.
        $band = max(1, min($padX, $padY));
        $share = (int) round(100 * (1 - 0.05 ** (1 / $band)));
        imagealphablending($original, false);
        foreach ($boxes as $box) {
            $left = max(0, (int) floor($box['left'] * $width / 100) - $padX);
            $top = max(0, (int) floor($box['top'] * $height / 100) - $padY);
            $right = min($width, (int) ceil($box['right'] * $width / 100) + $padX);
            $bottom = min($height, (int) ceil($box['bottom'] * $height / 100) + $padY);
            // A side at the frame's edge has nothing to fade into: there the mark goes whole
            // (a faded band at the top edge left the top of a logo showing).
            [$fadeLeft, $fadeTop, $fadeRight, $fadeBottom] = [$left > 0, $top > 0, $right < $width, $bottom < $height];
            for ($inset = 0; $inset <= $band; $inset++) {
                $x = $left + ($fadeLeft ? $inset : 0);
                $y = $top + ($fadeTop ? $inset : 0);
                $w = $right - ($fadeRight ? $inset : 0) - $x;
                $h = $bottom - ($fadeBottom ? $inset : 0) - $y;
                if ($w <= 0 || $h <= 0) {
                    break;
                }
                $inset < $band
                    ? imagecopymerge($original, $scaled, $x, $y, $x, $y, $w, $h, $share)
                    : imagecopy($original, $scaled, $x, $y, $x, $y, $w, $h);
            }
        }
        imagedestroy($scaled);
        imagedestroy($cleaned);

        return $original;
    }
}
