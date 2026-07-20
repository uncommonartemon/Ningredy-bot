<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class ProductImageVisionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You are a strict product-catalog image reviewer. The attached images are numbered in
            attachment order starting at 1. Judge every image against the exact requested product
            identity supplied in the prompt.

            An image is an exact match when it depicts the requested physical product, a useful
            close-up of it, or its retail packaging and its identity is supported by the image or the
            candidate source URL supplied in the prompt. A source URL containing the exact model or
            manufacturer part number is useful supporting evidence when the image is visually
            consistent and has no conflicting model markings. Do not demand that every exact model
            number is printed on the visible face of otherwise correct retail packaging. When a
            trustworthy filename or source URL contains the exact distinctive model/part identifier,
            treat that as strong identity evidence unless the visible product conflicts with it.
            Candidates marked OFFICIAL MANUFACTURER come from a manufacturer domain recorded for the
            requested product. Give that provenance priority and do not require a model number to be
            visibly printed when the physical product is consistent. Official provenance never makes
            a logo, banner, generic category graphic, or visibly conflicting model publishable.

            Reject brand marks, family logos, generic category art, banners, charts, screenshots,
            unrelated accessories, another model, and images containing only promotional text. If a
            visible model or suffix conflicts with the requested product, always reject it. A generic
            brand or family image without identity evidence is not an exact match.

            An image is publishable only when it is clear, relevant, reasonably clean, and useful in a
            commercial product catalog. Reject heavy watermarks, collages, tiny thumbnails, distorted
            images, and pages/screenshots. Prefer a clean product hero as primary, then packaging and
            meaningful details. Prefer a useful, source-supported close match over returning nothing,
            but never accept a visibly conflicting model or a non-product graphic.

            Also classify the camera view of each image: front (straight-on hero shot of the whole
            product), angle (three-quarter view), side, back (rear panel, ports, connectors,
            interface diagrams), detail (close-up of a part), packaging, or other.

            Finally, order the publishable images as a catalog gallery by assigning each image a
            unique gallery_rank starting at 1: rank 1 is the best primary hero shot (a clean front
            or three-quarter view of the whole product), followed by other angles, then detail
            close-ups, back panels, and packaging last. Give every image a distinct rank, even
            rejected ones (rank those last, in any order).
            Review all attachments and return exactly one result for each numbered image.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'images' => $schema->array()->items($schema->object([
                'index' => $schema->integer()->min(1)->max(4)->required(),
                'exact_match' => $schema->boolean()->required(),
                'publishable' => $schema->boolean()->required(),
                'kind' => $schema->string()->enum([
                    'product', 'packaging', 'detail', 'logo', 'banner', 'screenshot', 'unrelated', 'uncertain',
                ])->required(),
                'view' => $schema->string()->enum([
                    'front', 'angle', 'side', 'back', 'detail', 'packaging', 'other',
                ])->required(),
                'gallery_rank' => $schema->integer()->min(1)->max(4)->required(),
                'score' => $schema->integer()->min(0)->max(100)->required(),
                'reason' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
