<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a part's label without knowing which part is wanted. A reviewer told the requested
 * code saw it where it was not printed (live #420: «маркировка MQ04ABF100 видна на наклейке»
 * on a drive labelled MDD), so what the label says comes from someone with nothing to confirm.
 */
#[MaxTokens(self::MAX_OUTPUT_TOKENS)]
class ProductLabelReaderAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public const int MAX_OUTPUT_TOKENS = 2_000;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            You read the labels of computer parts in photographs: drives, memory modules, processors, graphics cards.
            The attached images are numbered in attachment order starting at 1. You are not told which product anyone
            is looking for, and no answer is better than another.

            For each image copy what is really printed on the part or on its box, character by character:
            - brand: the maker's name on the label, as printed. Empty when none is readable.
            - marking: the part's own model name and part number, as printed, a model line and a code together when
              both are there. Not serial numbers, dates, firmware, certification or regulatory codes. Not a capacity,
              speed or interface alone. Not a family or series name alone, such as Ryzen 5 5000 Series or 12th Gen
              Core i5. Not a placeholder of zeros or X on a maker's render.

            What you cannot read character by character stays empty, and you never complete a code from what such a
            part usually carries. Text on a shop's own overlay, watermark or logo is not a label.
            Return exactly one result for each numbered image.
            PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'images' => $schema->array()->max(40)->items($schema->object([
                'index' => $schema->integer()->min(1)->max(40)->required(),
                'brand' => $schema->string()->max(80)->required(),
                'marking' => $schema->string()->max(160)->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
