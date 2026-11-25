<?php

namespace App\Services\Products;

/** Public explanation only: never expose prompts, request context or evidence diagnostics. */
final class PhotoSourceNotice
{
    /**
     * What the storefront may say about a photo: facts that the page words itself in the
     * visitor's language. The reviewers' notes are Russian free text for the operator; on the
     * shop they stood in Russian under the gallery, the same remark twice in two wordings.
     * The keyboard is read from the frames' verdicts, which every stored review carries, so
     * older cards get the localized line too and lose the Russian one.
     */
    public static function fromContext(?array $context): ?array
    {
        $assessment = $context['_photo_applicability'] ?? null;
        $compatible = is_array($assessment) && ($assessment['verdict'] ?? null) === 'compatible';
        $keyboard = self::keyboard($context);
        $markings = self::markings($context);
        if (! $compatible && $keyboard === null && $markings === []) {
            return null;
        }

        return [
            'source_model' => self::text($assessment['source_model'] ?? null, 300),
            // The photos show another configuration in the same body.
            'other_configuration' => $compatible,
            // differs: the photos show another layout; unverified: it could not be compared.
            ...($keyboard === null ? [] : ['keyboard' => $keyboard]),
            // The marking is a brand name; the sentence around it is the page's own.
            ...($markings === [] ? [] : ['configuration_markings' => $markings, 'markings_may_differ' => true]),
        ];
    }

    /** The operator's notice, in Russian, with the reviewers' own words. */
    public static function russian(?array $context): ?string
    {
        $assessment = $context['_photo_applicability'] ?? null;
        $compatible = is_array($assessment) && ($assessment['verdict'] ?? null) === 'compatible';
        $visualDifferences = self::current($context) ? ($context['_photo_visual_review']['differences'] ?? []) : [];
        $visualDifferences = is_array($visualDifferences) ? $visualDifferences : [];
        $keyboardNotice = self::text($context['_photo_keyboard_reconciliation']['notice'] ?? null, 600);
        if ($keyboardNotice !== '') {
            $visualDifferences[] = $keyboardNotice;
        }
        $markings = self::markings($context);
        if (! $compatible && $visualDifferences === [] && $markings === []) {
            return null;
        }
        $model = self::text($assessment['source_model'] ?? null, 300);
        $differences = collect([...($compatible && is_array($assessment['differences'] ?? null) ? $assessment['differences'] : []), ...$visualDifferences])
            ->map(fn (mixed $value): string => self::text($value, 600))->filter()->unique()->values()->all();

        return ($compatible ? 'Фото другой комплектации с совместимым корпусом' : 'Особенности фотографий')
            .($model !== '' ? ': '.$model : '').'.'
            .($differences !== [] ? ' Отличия: '.implode(' ', $differences) : '')
            .($markings !== [] ? ' На фото наклейка: '.implode(', ', $markings)
                .' — наклейки процессора и видеокарты на фото производителя могут отличаться от комплектации товара.' : '');
    }

    /**
     * The keyboard in the photos against the product's layout. A frame judged against a layout
     * the card no longer has says nothing; the later comparison speaks instead.
     */
    private static function keyboard(?array $context): ?string
    {
        if (! self::current($context)) {
            return null;
        }
        $visual = $context['_photo_visual_review'];
        $reconciliation = is_array($context['_photo_keyboard_reconciliation'] ?? null) ? $context['_photo_keyboard_reconciliation'] : null;
        $stale = $reconciliation !== null && ($reconciliation['target'] ?? null) !== ($visual['keyboard_target'] ?? null);
        $frames = $stale ? collect() : collect($visual['frames'] ?? [])->filter(fn (mixed $frame): bool => is_array($frame));
        if ($frames->contains(fn (array $frame): bool => ($frame['keyboard_layout_match'] ?? null) === 'different')) {
            return 'differs';
        }
        $unreadable = $frames->contains(fn (array $frame): bool => ($frame['keyboard_layout_match'] ?? null) === 'unknown'
            && ($frame['keyboard_visibility'] ?? null) === 'visible_unreadable'
            && ($frame['keyboard_requirement_scope'] ?? null) === 'product');

        return $unreadable || self::text($reconciliation['notice'] ?? null, 600) !== '' ? 'unverified' : null;
    }

    /** @return list<string> */
    private static function markings(?array $context): array
    {
        return self::current($context)
            ? collect((array) ($context['_photo_visual_review']['configuration_markings'] ?? []))
                ->map(fn (mixed $marking): string => self::text($marking, 100))->filter()->unique()->values()->all()
            : [];
    }

    /** Whether the context holds a review of the frames actually kept. */
    private static function current(?array $context): bool
    {
        return ($context['_photo_visual_review']['scope'] ?? null) === 'current_downloaded_frames';
    }

    private static function text(mixed $value, int $length): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $length) : '';
    }
}
