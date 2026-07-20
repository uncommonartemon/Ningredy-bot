<?php

namespace App\Services\Products;

use Illuminate\Support\Str;

class ProductPublicDescription
{
    /**
     * @param array<string, mixed> $data
     * @return array{description: string, research_notes: ?string}
     */
    public function normalize(array $data): array
    {
        $description = $this->clean($data['description'] ?? null);
        $researchNotes = $this->clean($data['research_notes'] ?? null);

        if ($description === '' || $this->containsResearchReasoning($description)) {
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

    private function containsResearchReasoning(string $description): bool
    {
        return preg_match(
            '/(?:closest\s+(?:current\s+)?(?:match|product)|family[-\s]level\s+match|family\s+match|match\s+for|requested\s+product|user\s+request|search\s+(?:result|found)|product\s+found|exact\s+sku|not\s+confirmed|could\s+not\s+(?:confirm|verify)|confidence\s+score|mismatch|uncertaint|sources?\s+(?:show|indicate|list)|(?:official|manufacturer|retailer)\s+(?:site|pages?|sources?)\s+(?:confirm|show|list|indicate)|according\s+to|\bai\b|neural\s+network|price\s+(?:is\s+)?(?:not\s+)?(?:available|provided)|по\s+запрос|ближайш|найден|совпаден|источник|не\s+подтвержден|уверенност|несоответств|за\s+запит|знайден|джерел|невідповід|cena\s+není\s+uvedena|nalezen|zdroj)/iu',
            $description,
        ) === 1;
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
