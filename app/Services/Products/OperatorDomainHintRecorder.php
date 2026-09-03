<?php

namespace App\Services\Products;

use App\Models\ProductSourceDomain;

/**
 * A hint typed by the operator ("open the photo itself, not the thumbnail, to
 * get the large rendition") describes how a site behaves, so it must outlive
 * every recipe built from it: ResetCatalogTestData and each retraining round
 * drop product_gallery_recipes, while product_source_domains is deliberately
 * left alone. Until this existed the Telegram hint reached exactly one training
 * run as a one-off operator_hint and then evaporated, which is why the same
 * knowledge kept having to be retyped (or, worse, pinned into a migration).
 */
class OperatorDomainHintRecorder
{
    /**
     * Caps how many hints this recorder itself keeps, mirroring
     * FlagDomainRecipeNote::MAX_AUTO_NOTES so one domain cannot grow past what
     * the trainer prompt is willing to read. Lines typed by hand in Filament
     * carry no marker and are never pruned.
     */
    private const MAX_OPERATOR_NOTES = 8;

    private const MARKER = 'оператор';

    public function remember(string $url, ?string $hint): ?ProductSourceDomain
    {
        $hint = trim((string) $hint);
        $domain = ProductSourcePriority::host($url);

        if ($hint === '' || $domain === '') {
            return null;
        }

        // ProductSourceDomain is keyed by the www-stripped host, unlike
        // ProductGalleryRecipe which keeps www - looking it up any other way
        // silently creates a second row the trainer never reads.
        $settings = ProductSourceDomain::query()->firstOrCreate(['domain' => $domain]);
        $existing = array_values(array_filter(
            preg_split('/\R/', (string) $settings->agent_hint) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));
        $entry = '['.self::MARKER.' '.now()->toDateString().'] '.mb_substr($hint, 0, 1000);

        // Re-sending the same hint (a second retrain on the same problem) must
        // not stack duplicate lines.
        $existing = array_values(array_filter(
            $existing,
            fn (string $line): bool => $this->body($line) !== $this->body($entry),
        ));
        $manual = array_values(array_filter($existing, fn (string $line): bool => ! $this->isRecorded($line)));
        $recorded = array_values(array_filter($existing, fn (string $line): bool => $this->isRecorded($line)));
        $recorded[] = $entry;
        $recorded = array_slice($recorded, -self::MAX_OPERATOR_NOTES);

        $settings->update([
            'agent_hint' => mb_substr(implode("\n", [...$manual, ...$recorded]), 0, 4000),
        ]);

        return $settings->refresh();
    }

    private function isRecorded(string $line): bool
    {
        return preg_match($this->markerPattern(), trim($line)) === 1;
    }

    private function body(string $line): string
    {
        return trim((string) preg_replace($this->markerPattern(), '', trim($line)));
    }

    private function markerPattern(): string
    {
        return '/^\['.self::MARKER.'\s+\d{4}-\d{2}-\d{2}\]\s*/u';
    }
}
