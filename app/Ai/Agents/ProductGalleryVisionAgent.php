<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
class ProductGalleryVisionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const int MAX_OUTPUT_TOKENS = 4_000;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You are a read-only visual inspection tool for another agent that is locating a product gallery.
            Describe only visible evidence. Never decide which images to publish, never return filtered indices,
            never invent CSS selectors, and never claim that a visually identical chassis proves an exact SKU.

            Treat unusual angles, side/rear/top views, close-ups, a closed or folded product, lifestyle scenes,
            dramatic backgrounds, glow/effects and professional feature graphics as valid product-bearing frames
            whenever the requested product remains meaningfully visible. They are not failures by themselves.

            For each numbered attachment report whether the product is meaningfully visible, whether it appears
            visually consistent with the other attachments, its broad view, any visible conflicting brand/model/
            color marking, and the language of prominent text rendered inside the image pixels. English and Czech
            are allowed. Use "other" or "mixed" for prominent text in any other language; ignore tiny incidental
            text, ordinary brand/model labels and universal technical abbreviations.

            The overall coherent_single_product_gallery field only answers whether the supplied pixels plausibly
            form views/details/marketing frames of one physical product. Exact page identity and exact SKU must be
            established separately from page URL, title, SKU and specification evidence. Set needs_more_evidence
            when an attachment is too small, obscured, ambiguous, or the set is insufficient. Be explicit about
            uncertainty instead of converting it into a rejection.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'coherent_single_product_gallery' => $schema->boolean()->required(),
            'needs_more_evidence' => $schema->boolean()->required(),
            'images' => $schema->array()->max(4)->items($schema->object([
                'index' => $schema->integer()->min(1)->max(4)->required(),
                'product_visible' => $schema->boolean()->required(),
                'visually_consistent' => $schema->boolean()->required(),
                'view' => $schema->string()->enum([
                    'front', 'angle', 'side', 'back', 'top', 'detail', 'closed', 'lifestyle', 'feature', 'other',
                ])->required(),
                'prominent_text_language' => $schema->string()->enum([
                    'none', 'english', 'czech', 'other', 'mixed', 'uncertain',
                ])->required(),
                'visible_conflict' => $schema->boolean()->required(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
                'observation' => $schema->string()->max(600)->required(),
            ])->withoutAdditionalProperties())->required(),
            'summary' => $schema->string()->max(1000)->required(),
        ];
    }
}
