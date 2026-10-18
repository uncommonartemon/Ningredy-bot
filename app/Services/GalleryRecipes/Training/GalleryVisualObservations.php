<?php

namespace App\Services\GalleryRecipes\Training;

/** Pixel observations shared by rounds of one URL's training, never a domain verdict. */
final class GalleryVisualObservations
{
    private array $batches = [];

    public function record(array $result): void
    {
        if (($result['ok'] ?? false) !== true || ! is_array($result['observation'] ?? null)
            || ($result['inspected_urls'] ?? []) === []) {
            return;
        }

        $urls = $result['inspected_urls'];
        sort($urls);
        $this->batches[hash('sha256', json_encode($urls))] = $result;
    }

    public function all(): array
    {
        return array_values($this->batches);
    }
}
