<?php

namespace App\Services\Products;

/** Search-scoped bytes, spilled to temporary storage and closed on release. */
class GalleryDownloadCache
{
    private array $entries = [];

    private int $bytes = 0;

    public function remember(string $url, ?string $referer, array $download): void
    {
        $key = $this->key($url, $referer);
        if (isset($this->entries[$key]) || ! is_string($download['bytes'] ?? null)) {
            return;
        }
        $size = strlen($download['bytes']);
        if ($size === 0 || $this->bytes + $size > 64 * 1024 * 1024) {
            return;
        }
        $stream = fopen('php://temp/maxmemory:262144', 'w+b');
        if ($stream === false) {
            return;
        }
        if (fwrite($stream, $download['bytes']) !== $size) {
            fclose($stream);

            return;
        }
        unset($download['bytes'], $download['confirmed_gallery'], $download['partial_gallery']);
        $this->entries[$key] = ['stream' => $stream, 'size' => $size, 'metadata' => $download];
        $this->bytes += $size;
    }

    public function get(string $url, ?string $referer, int $maxBytes): ?array
    {
        $entry = $this->entries[$this->key($url, $referer)] ?? null;
        if ($entry === null || $entry['size'] > $maxBytes || ! rewind($entry['stream'])) {
            return null;
        }
        $bytes = stream_get_contents($entry['stream']);

        return is_string($bytes) && strlen($bytes) === $entry['size']
            ? [...$entry['metadata'], 'bytes' => $bytes]
            : null;
    }

    private function key(string $url, ?string $referer): string
    {
        // Do not collapse query parameters or share bytes between product pages.
        return hash('sha256', $url.chr(10).($referer ?? ''));
    }

    public function __destruct()
    {
        foreach ($this->entries as $entry) {
            fclose($entry['stream']);
        }
    }
}
