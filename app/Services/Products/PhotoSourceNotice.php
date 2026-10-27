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
        $keyboardNotice = self::text($context['_photo_keyboard_reconciliation']['notice'] ?? null, 600);
        if ($keyboardNotice !== '') {
            $visualDifferences = [...(is_array($visualDifferences) ? $visualDifferences : []), $keyboardNotice];
        }
        $compatible = is_array($assessment) && ($assessment['verdict'] ?? null) === 'compatible';
        $markings = ($context['_photo_visual_review']['scope'] ?? null) === 'current_downloaded_frames'
            ? collect((array) ($context['_photo_visual_review']['configuration_markings'] ?? []))
                ->map(fn (mixed $marking): string => self::text($marking, 100))->filter()->unique()->values()->all()
            : [];
        if (! $compatible && (! is_array($visualDifferences) || $visualDifferences === []) && $markings === []) {
            return null;
        }

        return [
            'source_model' => self::text($assessment['source_model'] ?? null, 300),
            'differences' => collect([
                ...($compatible && is_array($assessment['differences'] ?? null) ? $assessment['differences'] : []),
                ...(is_array($visualDifferences) ? $visualDifferences : []),
            ])
                ->map(fn (mixed $value): string => self::text($value, 600))->filter()->unique()->values()->all(),
            // The storefront words this itself in the buyer's language; the marking is a brand name.
            ...($markings === [] ? [] : ['configuration_markings' => $markings, 'markings_may_differ' => true]),
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
            .($notice['differences'] !== [] ? ' Отличия: '.implode(' ', $notice['differences']) : '')
            .(($notice['configuration_markings'] ?? []) !== [] ? ' На фото наклейка: '.implode(', ', $notice['configuration_markings'])
                .' — наклейки процессора и видеокарты на фото производителя могут отличаться от комплектации товара.' : '');
    }

    private static function text(mixed $value, int $length): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $length) : '';
    }
}
