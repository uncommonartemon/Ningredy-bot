<?php

namespace App\Services\Products;

class BrowserProductImageTransferStore
{
    /** @var array<string, array{bytes:string, source_url:string, mime_type:string, width:int, height:int}> */
    private array $images = [];

    /** @param array<int, mixed> $items */
    public function remember(array $items, string $directory): void
    {
        $root = realpath($directory);
        if ($root === false) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['url'] ?? null) || ! is_string($item['path'] ?? null)) {
                continue;
            }

            $path = realpath($item['path']);
            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $bytes = @file_get_contents($path);
            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > 8_388_608) {
                continue;
            }

            $dimensions = @getimagesizefromstring($bytes);
            if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
                continue;
            }

            $sourceUrl = is_string($item['final_url'] ?? null) && filter_var($item['final_url'], FILTER_VALIDATE_URL)
                ? $item['final_url']
                : $item['url'];
            $download = [
                'bytes' => $bytes,
                'source_url' => $sourceUrl,
                'mime_type' => $dimensions['mime'] ?? 'application/octet-stream',
                'width' => (int) $dimensions[0],
                'height' => (int) $dimensions[1],
            ];

            $this->images[ProductImageStorage::normalizeCandidateUrl($item['url'])] = $download;
            $this->images[ProductImageStorage::normalizeCandidateUrl($sourceUrl)] = $download;
        }
    }

    /** @return array{bytes:string, source_url:string, mime_type:string, width:int, height:int}|null */
    public function get(string $url, int $maxBytes): ?array
    {
        $image = $this->images[ProductImageStorage::normalizeCandidateUrl($url)] ?? null;

        return is_array($image) && strlen($image['bytes']) <= $maxBytes ? $image : null;
    }
}
