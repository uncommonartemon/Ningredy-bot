<?php

namespace App\Ai\Tools;

use App\Services\Products\ProductImageResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Downloads image addresses the agent proposes and says how large each one is.
 * The bytes never reach the agent; only status and size, through the same safe
 * downloader every product photo goes through.
 */
class MeasureImageUrls implements Tool
{
    private int $measured = 0;

    public function __construct(private readonly ?string $refererUrl = null, private readonly int $budget = 24) {}

    public function description(): Stringable|string
    {
        return 'Download up to 8 image addresses you propose and get each one\'s status, width and height. '
            .'Use it to test where this site keeps the larger rendition of its thumbnails before you answer.';
    }

    public function handle(Request $request): Stringable|string
    {
        $urls = collect((array) $request->array('urls'))
            ->filter(fn (mixed $url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
            ->unique()->take(min(8, max(0, $this->budget - $this->measured)))->values()->all();
        if ($urls === []) {
            return json_encode(['measured' => [], 'note' => 'No valid addresses, or the measuring budget for this site is spent.']);
        }
        $this->measured += count($urls);
        $downloads = app(ProductImageResolver::class)->downloadMany($urls,
            $this->refererUrl === null ? [] : array_fill_keys($urls, $this->refererUrl));

        return json_encode(['measured' => array_map(function (string $url) use ($downloads): array {
            $download = $downloads[$url]['download'] ?? null;

            return is_array($download)
                ? ['url' => $url, 'status' => 'image', 'width' => (int) ($download['width'] ?? 0), 'height' => (int) ($download['height'] ?? 0)]
                : ['url' => $url, 'status' => 'failed', 'reason' => mb_substr((string) ($downloads[$url]['failure'] ?? 'not an image'), 0, 160)];
        }, $urls)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'urls' => $schema->array()->items($schema->string()->max(2048))->max(8)->required()
                ->description('Absolute http(s) image addresses to download and measure.'),
        ];
    }
}
