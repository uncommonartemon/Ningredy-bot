<?php

namespace App\Services\Products;

/** Reconcile new text facts with existing pixel evidence, without inventing a visual verdict. */
final class ProductPhotoKeyboardContext
{
    private const KEYS = ['keyboard_layout', 'keyboard_language', 'keyboard_localization', 'keyboard_locale'];

    public static function target(array $specifications): array
    {
        return collect($specifications)->filter(fn ($fact): bool => is_array($fact)
            && in_array(strtolower((string) ($fact['key'] ?? '')), self::KEYS, true))
            ->pluck('value')->filter(fn ($value): bool => is_string($value))->map(fn (string $value): string => trim(strip_tags($value)))
            ->filter()->unique()->sort()->values()->all();
    }

    public static function explicitlyRequiresPhotos(array $input): bool
    {
        return collect($input['input_evidence'] ?? [])->contains(fn (array $fact): bool => ($fact['source_kind'] ?? null) === 'user_text'
            && (in_array(strtolower((string) ($fact['key'] ?? '')), [
                'photo_keyboard_layout', 'image_keyboard_layout', 'required_photo_keyboard_layout',
            ], true) || (($fact['requirement_scope'] ?? null) === 'photos'
                && in_array(strtolower((string) ($fact['key'] ?? '')), self::KEYS, true))) && filled($fact['value'] ?? null));
    }

    /** The photo constraint is independent of the sold product's regional layout. */
    public static function photoRequirement(array $input): array
    {
        return [
            'request_hash' => hash('sha256', trim((string) ($input['original_operator_request'] ?? ''))),
            'explicit_facts' => collect($input['input_evidence'] ?? [])
                ->filter(fn (array $fact): bool => self::explicitlyRequiresPhotos(['input_evidence' => [$fact]]))
                ->map(fn (array $fact): array => ['key' => $fact['key'], 'value' => trim((string) $fact['value'])])
                ->sortBy('key')->values()->all(),
        ];
    }

    public static function reconcile(array $context, array $specifications, array $input = []): array
    {
        $visual = $context['_photo_visual_review'] ?? [];
        // This helper cannot supply the missing physical/quality examination.
        if (($visual['scope'] ?? null) !== 'current_downloaded_frames') {
            return $context;
        }
        $frames = collect($visual['frames'] ?? [])->filter(fn ($frame): bool => is_array($frame));
        $visible = $frames->filter(fn (array $frame): bool => ! in_array($frame['keyboard_visibility'] ?? 'unknown', ['not_visible', 'not_applicable'], true));
        $target = self::target($specifications);
        $reviewedTarget = $visual['keyboard_target'] ?? null;
        $changed = $reviewedTarget !== $target;
        $unexamined = $visible->contains(fn (array $frame): bool => in_array($frame['keyboard_requirement_scope'] ?? 'unknown', ['none', 'unknown'], true));
        $strict = self::explicitlyRequiresPhotos($input)
            || $frames->contains(fn (array $frame): bool => ($frame['keyboard_requirement_scope'] ?? null) === 'photos');
        $samePhotoRequirement = ($visual['photo_requirement'] ?? null) === self::photoRequirement($input);
        $pending = $strict ? $visible->filter(fn (array $frame): bool => ! ($samePhotoRequirement
            && ($frame['keyboard_requirement_scope'] ?? null) === 'photos'
            && ($frame['keyboard_visibility'] ?? null) === 'readable'
            && ($frame['keyboard_layout_match'] ?? null) === 'match'))
            ->map(fn (array $frame): string => (string) ($frame['source_url'] ?? '*'))->unique()->values()->all() : [];
        if ($strict && $frames->isEmpty()) {
            $pending = ['*'];
        }

        if ($target === [] && empty($reviewedTarget) && $pending === []) {
            unset($context['_photo_keyboard_reconciliation']);

            return $context;
        }

        if (! $changed && ! $unexamined && $pending === []) {
            unset($context['_photo_keyboard_reconciliation']);

            return $context;
        }

        // Notes from a review against CZ must not describe a newly selected IT offer.
        if ($changed) {
            $oldNotes = $frames->pluck('keyboard_layout_note')->filter()->all();
            $context['_photo_visual_review']['differences'] = array_values(array_diff($visual['differences'] ?? [], $oldNotes));
            if (is_array($context['_photo_applicability']['differences'] ?? null)) {
                $context['_photo_applicability']['differences'] = array_values(array_diff($context['_photo_applicability']['differences'], $oldNotes));
            }
        }
        $notice = $target !== [] && ($visible->isNotEmpty() || $frames->isEmpty())
            ? 'Раскладка на фотографиях не сверена с итоговой карточкой; у товара — '.implode(', ', $target).'.'
            : null;
        $context['_photo_keyboard_reconciliation'] = [
            'target' => $target,
            'status' => $pending !== [] ? 'requires_photo_review' : 'not_compared',
            'notice' => $notice,
            'pending_source_urls' => $pending,
        ];

        return $context;
    }

    public static function pendingSources(array $context): array
    {
        return $context['_photo_keyboard_reconciliation']['pending_source_urls'] ?? [];
    }
}
