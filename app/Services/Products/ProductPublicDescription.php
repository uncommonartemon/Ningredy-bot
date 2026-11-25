<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductPublicDescription
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{description: string, research_notes: ?string}
     */
    public function normalize(array $data): array
    {
        $description = $this->clean($data['description'] ?? null);
        $researchNotes = $this->clean($data['research_notes'] ?? null);

        if ($description === '' || $this->containsResearchReasoning($description, $data)) {
            if ($description !== '') {
                $researchNotes = $this->clean(implode("\n\n", array_filter([
                    $researchNotes,
                    "Original research summary:\n{$description}",
                ])));
            }

            $description = $this->buildTechnicalDescription($data);
        }

        return [
            'description' => Str::limit($description, 5000, ''),
            'research_notes' => $researchNotes === '' ? null : Str::limit($researchNotes, 5000, ''),
        ];
    }

    /**
     * Reasoning is the writer speaking about the search or about itself. The
     * product's own name, model and specification values are never that, so
     * they are taken out before the check: "Ryzen AI 5 330" or "HX AI" is a
     * product, and a laptop of that kind is described by its AI features. The
     * word AI counts only where the text calls itself AI-made.
     *
     * @param  array<string, mixed>  $data
     */
    private function containsResearchReasoning(string $description, array $data): bool
    {
        return preg_match(
            '/(?:closest\s+(?:current\s+)?(?:match|product)|family[-\s]level\s+match|family\s+match|match\s+for|requested\s+product|user\s+request|search\s+(?:result|found)|product\s+found|exact\s+sku|not\s+confirmed|could\s+not\s+(?:confirm|verify)|confidence\s+score|mismatch|uncertaint|sources?\s+(?:show|indicate|list)|(?:official|manufacturer|retailer)\s+(?:site|pages?|sources?)\s+(?:confirm|show|list|indicate)|according\s+to|\bas\s+an?\s+(?:ai|language\s+model)\b|\b(?:generated|written|created|compiled)\s+(?:by|with|using)\s+(?:an?\s+)?(?:ai|neural\s+network|language\s+model)\b|\bai[-\s](?:generated|written)\b|price\s+(?:is\s+)?(?:not\s+)?(?:available|provided)|по\s+запрос|ближайш|найден|совпаден|источник|не\s+подтвержден|уверенност|несоответств|за\s+запит|знайден|джерел|невідповід|cena\s+není\s+uvedena|nalezen|zdroj)/iu',
            $this->withoutOwnWords($description, $data),
        ) === 1;
    }

    /** @param array<string, mixed> $data */
    private function withoutOwnWords(string $description, array $data): string
    {
        $own = collect([$data['title'] ?? null, $data['model'] ?? null])
            ->merge(collect($data['specifications'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): mixed => $item['value'] ?? null))
            ->map(fn (mixed $value): string => $this->clean($value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 2)
            ->unique()
            // Longest first, so a full name goes before a shorter value inside it.
            ->sortByDesc(fn (string $value): int => mb_strlen($value))
            ->values();

        foreach ($own as $value) {
            $description = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu', ' ', $description) ?? $description;
        }

        return $description;
    }

    /** @param array<string, mixed> $data */
    private function buildTechnicalDescription(array $data): string
    {
        $title = $this->clean($data['title'] ?? null) ?: 'Product';
        $model = $this->clean($data['model'] ?? null);
        $color = $this->clean($data['color'] ?? null);
        $identity = [];

        if ($model !== '' && ! Str::contains(Str::lower($title), Str::lower($model))) {
            $identity[] = "Model: {$model}";
        }

        if ($color !== '') {
            $identity[] = "Color: {$color}";
        }

        $specifications = collect($data['specifications'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?string {
                $name = $this->clean($item['name'] ?? $item['key'] ?? null);
                $value = $this->clean($item['value'] ?? null);

                return $name !== '' && $value !== '' ? "{$name}: {$value}" : null;
            })
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();
        $parts = [rtrim($title, '.').'.'];

        if ($identity !== []) {
            $parts[] = implode('. ', $identity).'.';
        }

        if ($specifications !== []) {
            $parts[] = 'Specifications: '.implode('; ', $specifications).'.';
        }

        return implode(' ', $parts);
    }

    private function clean(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\[([^]]+)]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = str_replace(['**', '__', '`', '###', '##'], '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
