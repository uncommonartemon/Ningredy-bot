<?php

namespace App\Ai\Agents;

use App\Ai\Tools\MeasureImageUrls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Learns where one site keeps the larger rendition of its thumbnails: it proposes
 * addresses, measures them, and answers with one rewrite rule. The server measures
 * the rule again on the site's own frames before it keeps it for that site.
 */
#[MaxTokens(3_000)]
#[MaxSteps(5)]
class RenditionRuleAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function __construct(private readonly ?string $refererUrl = null) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            A product page serves its photographs only small. You get those thumbnail addresses
            with their measured sizes, the minimum width a photo needs, and the rules already
            tried on this image host. Find where the same site keeps the larger rendition of the
            SAME frames: reason from the address (a CMS or CDN cache folder, a size token or size
            folder, a thumbnail folder, an image service path) and measure your candidates with
            MeasureImageUrls before you answer. A larger picture of something else is not a
            rendition: the frame must stay the same.

            Answer decision "rule" only with a rule you measured. find is a regular expression
            over the URL PATH only (no scheme, host or query); replace is the new path, with {1},
            {2} for its captured groups; flags is "i" or "". Applied to the given thumbnail paths
            it must give at least two addresses you measured at or above the minimum width. Keep
            it as narrow as the evidence - this site's folder or file-name shape, not every
            address on the web. Otherwise answer decision "none" and say what you found.
            PROMPT;
    }

    public function tools(): iterable
    {
        return [new MeasureImageUrls($this->refererUrl)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'decision' => $schema->string()->enum(['rule', 'none'])->required(),
            'find' => $schema->string()->max(400)->required(),
            'flags' => $schema->string()->enum(['i', ''])->required(),
            'replace' => $schema->string()->max(400)->required(),
            'reason' => $schema->string()->max(600)->required(),
        ];
    }
}
