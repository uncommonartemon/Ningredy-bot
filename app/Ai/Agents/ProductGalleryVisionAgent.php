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
            dramatic backgrounds and glow/effects as valid product-bearing frames whenever the requested product
            is the clear subject. They are not failures by themselves. Report illustrations of internal
            components, infographics and feature slides built around a callout or graphic as not product-bearing:
            the catalog publishes product photos the way an online store's gallery does.

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

            Also report the visible capture_style and visible_condition of each frame so the caller can avoid
            spending recipe-training rounds on a gallery of clearly unsuitable seller snapshots. catalog_asset
            includes professional renders, packaging, details and lifestyle/feature photography. personal_snapshot
            requires concrete visual evidence of an individual seller's physical specimen: casual handling,
            a household/workbench setup with that specimen, private sale annotations, or visible wear/damage.
            A non-white background, a hand, unusual composition, or the source being a marketplace is not enough
            by itself: professional lifestyle photography may contain all of these. Use uncertain when evidence
            is insufficient. Describe the actual evidence in quality_evidence; do not infer it from URL/domain.
            A small thumbnail cannot establish that the source originals are blurred, low-resolution or damaged.
            Do not generalize one bad frame to its neighbors or the whole gallery. This remains observational:
            the calling agent must establish coverage of that gallery, and the final reviewer decides publication.

            If the original operator request explicitly specifies a color, assess that color across the
            supplied views together. A side, bottom, port detail, reflection, or shadow may reveal no reliable
            chassis color: report uncertain for that frame, never a conflict merely because color is unclear.
            Use informative lid/body views to assess the set. Report a conflict only from visible contradictory
            evidence. Distinguish a mixed-color set from ambiguous lighting. If no color was explicitly requested,
            report not_requested. A model's chosen/default color is not an explicit operator requirement.
            Matching pixels do not establish one DOM slider: the calling agent must prove container, selected
            variant and traversal continuity. These observations apply only to this page and this set of images.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'requested_color_assessment' => $schema->string()->enum([
                'matches', 'conflicts', 'uncertain', 'not_requested',
            ])->required(),
            'color_evidence' => $schema->string()->max(1000)->required(),
            'coherent_single_product_gallery' => $schema->boolean()->required(),
            'needs_more_evidence' => $schema->boolean()->required(),
            'images' => $schema->array()->max(4)->items($schema->object([
                'index' => $schema->integer()->min(1)->max(4)->required(),
                'product_visible' => $schema->boolean()->required(),
                'color_observability' => $schema->string()->enum(['clear', 'uncertain', 'not_visible'])->required(),
                'visually_consistent' => $schema->boolean()->required(),
                'view' => $schema->string()->enum([
                    'front', 'angle', 'side', 'back', 'top', 'detail', 'closed', 'lifestyle', 'feature', 'other',
                ])->required(),
                'prominent_text_language' => $schema->string()->enum([
                    'none', 'english', 'czech', 'other', 'mixed', 'uncertain',
                ])->required(),
                'visible_conflict' => $schema->boolean()->required(),
                'capture_style' => $schema->string()->enum([
                    'catalog_asset', 'personal_snapshot', 'screenshot', 'uncertain',
                ])->required(),
                'visible_condition' => $schema->string()->enum([
                    'no_obvious_issue', 'used_or_damaged', 'uncertain',
                ])->required(),
                'quality_evidence' => $schema->string()->max(600)->required(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
                'observation' => $schema->string()->max(600)->required(),
            ])->withoutAdditionalProperties())->required(),
            'summary' => $schema->string()->max(1000)->required(),
        ];
    }
}
