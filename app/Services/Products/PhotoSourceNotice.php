<?php

namespace App\Services\Products;

/** Public explanation only: never expose prompts, request context or evidence diagnostics. */
final class PhotoSourceNotice
{
    public static function fromContext(?array $context): ?array
    {
        $assessment = $context['_photo_applicability'] ?? null;
        $visualDifferences = ($context['_photo_visual_review']['scope'] ?? null) === 'current_downloaded_frames'
            ? ($context['_photo_visual_review']['differences'] ?? []) : [];
        $compatible = is_array($assessment) && ($assessment['verdict'] ?? null) === 'compatible';
        if (! $compatible && (! is_array($visualDifferences) || $visualDifferences === [])) {
            return null;
        }

        return [
            'source_model' => self::text($assessment['source_model'] ?? null, 300),
            'differences' => collect([
                ...($compatible && is_array($assessment['differences'] ?? null) ? $assessment['differences'] : []),
                ...(is_array($visualDifferences) ? $visualDifferences : []),
            ])
                ->map(fn (mixed $value): string => self::text($value, 600))->filter()->unique()->values()->all(),
        ];
    }

    public static function russian(?array $context): ?string
    {
        $notice = self::fromContext($context);
        if ($notice === null) {
            return null;
        }

        return (($context['_photo_applicability']['verdict'] ?? null) === 'compatible'
            ? 'Фото другой комплектации с совместимым корпусом' : 'Особенности фотографий')
            .($notice['source_model'] !== '' ? ': '.$notice['source_model'] : '').'.'
            .($notice['differences'] !== [] ? ' Отличия: '.implode(' ', $notice['differences']) : '');
    }

    private static function text(mixed $value, int $length): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $length) : '';
    }
}
