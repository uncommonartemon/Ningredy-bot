<?php

namespace App\Services\ProductSearch\Catalogs;

/**
 * A search or an address written by the operator with {code}, {model} and
 * {brand} in it, filled with what is known about the product. Shared by the
 * brand catalogs and the reference sites.
 */
final class CodeTemplate
{
    /**
     * The template filled with each code (or the model when it asks for {model}).
     * A search the agent runs itself keeps a placeholder it can fill from what it
     * learns ($keepUnfilled); an address needs every value or is left out.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function fill(?string $template, array $codes, ?string $model, ?string $brand, bool $encode, bool $keepUnfilled = false): array
    {
        $template = trim((string) $template);
        if ($template === '') {
            return [];
        }
        $value = fn (string $raw): string => $encode ? rawurlencode($raw) : $raw;
        $base = filled($brand) ? str_replace('{brand}', $value((string) $brand), $template) : $template;
        if ($keepUnfilled && ((str_contains($base, '{code}') && $codes === []) || (str_contains($base, '{model}') && blank($model))
            || str_contains($base, '{brand}'))) {
            return [$base];
        }
        if (str_contains($base, '{brand}')) {
            return [];
        }
        if (str_contains($base, '{code}')) {
            return collect($codes)->map(fn (string $code): string => str_replace('{code}', $value($code), $base))
                ->map(fn (string $filled): string => str_contains($filled, '{model}')
                    ? (filled($model) ? str_replace('{model}', $value((string) $model), $filled) : '') : $filled)
                ->filter()->unique()->take(3)->values()->all();
        }
        if (str_contains($base, '{model}')) {
            return filled($model) ? [str_replace('{model}', $value((string) $model), $base)] : [];
        }

        return [$base];
    }
}
