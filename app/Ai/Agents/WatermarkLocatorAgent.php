<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Finds where a shop's marks lie over a product photo, so that only those places are cleaned.
 * An image model asked to clean the whole picture redraws small print too: on a real KIOXIA
 * frame it turned the label's KXG60ZNV512G into KXG602NV512G, and a part's label is its identity.
 */
#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
class WatermarkLocatorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const int MAX_OUTPUT_TOKENS = 2_000;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You find what a shop or website laid over a product photo: watermarks, logos, website addresses,
            stamps, badges, price tags and captions added on top of the picture, solid or semi-transparent.
            The product's own printed text, labels, stickers, logos and markings are part of the product and are
            never listed.

            For each overlay give a box in percent of the image: left, top, right and bottom, where 0 is the left
            or top edge and 100 the right or bottom edge. Make each box generous enough to cover the overlay
            whole, its faint edges and shadow too. Return an empty list when nothing is laid over the photo.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'overlays' => $schema->array()->max(12)->items($schema->object([
                'what' => $schema->string()->max(120)->required(),
                'left' => $schema->integer()->min(0)->max(100)->required(),
                'top' => $schema->integer()->min(0)->max(100)->required(),
                'right' => $schema->integer()->min(0)->max(100)->required(),
                'bottom' => $schema->integer()->min(0)->max(100)->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
