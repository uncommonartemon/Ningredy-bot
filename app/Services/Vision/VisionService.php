<?php

namespace App\Services\Vision;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\GalleryTextLanguageAgent;
use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\TelegramPhotoTextAgent;
use Closure;
use RuntimeException;
use UnexpectedValueException;

/** Explicit operations, not one prompt that silently changes authority. */
class VisionService
{
    public function __construct(
        private readonly VisionRunner $runner,
        private readonly VisionImagePreparer $images,
    ) {}

    public function readText(string $bytes, VisionCallContext $context, string $prompt = 'Extract product-identifying text or describe the visible product.'): array
    {
        return $this->runner->run('read_text', TelegramPhotoTextAgent::make(), $prompt,
            [$this->images->fromBytes($bytes, 'telegram-photo.jpg')], $context, function (array $data): array {
                if (trim((string) ($data['text'] ?? '')) === '') {
                    throw new RuntimeException('Photo text extraction is empty.');
                }

                return $data;
            });
    }

    public function inspectGallery(string $prompt, array $attachments, VisionCallContext $context): array
    {
        return $this->runner->run('inspect_gallery', ProductGalleryVisionAgent::make(), $prompt, $attachments, $context);
    }

    /** Domain-specific validation stays with the existing product reviewer. */
    public function reviewGallery(string $prompt, array $attachments, VisionCallContext $context, Closure $validate): array
    {
        return $this->runner->run('review_gallery', ProductImageVisionAgent::make(), $prompt, $attachments, $context, $validate);
    }

    public function checkGalleryFrames(string $prompt, array $attachments, VisionCallContext $context): array
    {
        return $this->runner->run('check_gallery_frames', GalleryFrameReviewAgent::make(), $prompt, $attachments, $context,
            function (array $data) use ($attachments): array {
                foreach (['foreign_text_frames', 'non_product_frames', 'uncertain_content_frames'] as $field) {
                    $indices = $data[$field] ?? null;
                    if (! is_array($indices) || ! array_is_list($indices)
                        || collect($indices)->contains(fn (mixed $index): bool => (! is_int($index) && ! (is_string($index) && ctype_digit($index)))
                            || (int) $index < 1 || (int) $index > count($attachments))) {
                        throw new UnexpectedValueException("Invalid {$field}: expected a list of integer indices within this batch.");
                    }
                }
                if (array_intersect($data['non_product_frames'], $data['uncertain_content_frames']) !== []) {
                    throw new UnexpectedValueException('A frame cannot be both confirmed non-product and uncertain.');
                }
                $cover = $data['cover_frame'] ?? 0;
                if (! is_int($cover) || $cover < 0 || $cover > count($attachments)
                    || in_array($cover, [...$data['foreign_text_frames'], ...$data['non_product_frames'], ...$data['uncertain_content_frames']])) {
                    throw new UnexpectedValueException('Invalid cover_frame: expected 0 or an index of a frame not listed as a problem.');
                }
                $data['cover_frame'] = $cover;

                return $data;
            });
    }

    public function checkTextLanguage(string $prompt, array $attachments, VisionCallContext $context): array
    {
        return $this->runner->run('check_text_language', GalleryTextLanguageAgent::make(), $prompt, $attachments, $context,
            function (array $data) use ($attachments): array {
                $indices = $data['foreign_text_frames'] ?? null;
                if (! is_array($indices) || ! array_is_list($indices)
                    || collect($indices)->contains(fn (mixed $index): bool => (! is_int($index) && ! (is_string($index) && ctype_digit($index)))
                        || (int) $index < 1 || (int) $index > count($attachments))) {
                    throw new UnexpectedValueException('Invalid foreign_text_frames: expected a list of integer indices within this batch.');
                }

                return $data;
            });
    }
}
