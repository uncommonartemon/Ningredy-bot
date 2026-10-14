<?php

namespace App\Services\Vision;

use App\Services\Products\ProductImageEncoder;
use GdImage;
use Laravel\Ai\Files\Image;
use RuntimeException;
use Throwable;

class VisionImagePreparer
{
    private const ACCEPTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(private readonly ProductImageEncoder $encoder) {}

    /** Inspect actual bytes, not a URL extension or an HTTP Content-Type. */
    public function prepare(array $download): ?array
    {
        $bytes = $download['bytes'] ?? null;
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $mime = $info['mime'] ?? '';
        if (in_array($mime, self::ACCEPTED_MIME_TYPES, true)) {
            return ['bytes' => $bytes, 'mime_type' => $mime];
        }
        if (! $this->encoder->isSafeToDecode($info[0], $info[1])) {
            return null;
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            return null;
        }
        try {
            $converted = $this->encoder->toWebp($decoded);

            return ['bytes' => $converted['bytes'], 'mime_type' => 'image/webp'];
        } catch (Throwable) {
            return null;
        } finally {
            imagedestroy($decoded);
        }
    }

    public function fromBytes(string $bytes, string $name, string $detail = 'high'): Image
    {
        $prepared = $this->prepare(['bytes' => $bytes]);
        if ($prepared === null) {
            throw new RuntimeException('Unsupported or invalid image for Vision.');
        }

        return $this->attachment($prepared, $name, $detail);
    }

    public function attachment(array $prepared, string $name, string $detail): Image
    {
        return Image::fromBase64(base64_encode($prepared['bytes']), $prepared['mime_type'])
            ->as($name)->withProviderOptions(['detail' => $detail]);
    }

    /** Same gallery thumbnail policy as before; never resize the stored original. */
    public function thumbnail(GdImage $image, string $name, string $detail): Image
    {
        $ratio = min(640 / imagesx($image), 640 / imagesy($image), 1);
        $thumbnail = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
        if ($thumbnail === false) {
            throw new RuntimeException('Could not prepare an image thumbnail for Vision.');
        }
        ob_start();
        try {
            if (! imageistruecolor($thumbnail)) {
                imagepalettetotruecolor($thumbnail);
            }
            imagewebp($thumbnail, null, 78);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($thumbnail);
        }
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Could not prepare an image thumbnail for Vision.');
        }

        return $this->attachment(['bytes' => $bytes, 'mime_type' => 'image/webp'], $name, $detail);
    }
}
