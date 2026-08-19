<?php

namespace App\Services\Products;

class ProductPageLayoutFingerprint
{
    /** @param array<string, mixed> $scout */
    public function make(array $scout): ?string
    {
        $signals = collect([
            ...$this->selectors($scout['interactive_controls'] ?? []),
            ...$this->selectors($scout['action_candidates'] ?? []),
            ...$this->selectors($scout['image_candidates'] ?? [], ['selector', 'parent_control_selector']),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->unique()
            ->sort()
            ->take(200)
            ->values()
            ->all();

        return $signals === []
            ? null
            : hash('sha256', json_encode($signals, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @return array<int, string> */
    private function selectors(mixed $items, array $keys = ['selector']): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->flatMap(fn (array $item): array => collect($keys)
                ->map(fn (string $key): mixed => $item[$key] ?? null)
                ->filter(fn (mixed $value): bool => is_string($value))
                ->all())
            ->values()
            ->all();
    }
}
