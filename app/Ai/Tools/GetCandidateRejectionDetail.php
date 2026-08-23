<?php

namespace App\Ai\Tools;

use App\Models\ProductSourceAttempt;
use App\Services\Products\ProductImageStorage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetCandidateRejectionDetail implements Tool
{
    public function __construct(
        private readonly string $url,
        private readonly string $domain,
    ) {}

    public function description(): Stringable|string
    {
        return 'Look up the real, already-recorded reason a specific candidate image URL was rejected during '
            .'download (too small, duplicate of another candidate, unreachable, unsafe to decode, ...). Use '
            .'this before assuming an image is simply missing or blaming the selector - the actual reason may '
            .'be resolution or a download-layer problem the recipe cannot fix.';
    }

    public function handle(Request $request): Stringable|string
    {
        $imageUrl = trim((string) $request->string('image_url'));

        if ($imageUrl === '') {
            return json_encode(['found' => false], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $targetKey = ProductImageStorage::imageAssetKey($imageUrl);

        $match = ProductSourceAttempt::query()
            ->where('domain', $this->domain)
            ->whereIn('phase', ['image_download', 'fallback_image_download'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->flatMap(function (ProductSourceAttempt $attempt): array {
                $output = is_array($attempt->output) ? $attempt->output : [];

                return is_array($output['rejected_candidates'] ?? null) ? $output['rejected_candidates'] : [];
            })
            ->first(function (mixed $candidate) use ($targetKey): bool {
                return is_array($candidate)
                    && is_string($candidate['url'] ?? null)
                    && ProductImageStorage::imageAssetKey($candidate['url']) === $targetKey;
            });

        if (! is_array($match)) {
            return json_encode(['found' => false], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'found' => true,
            'url' => $match['url'] ?? $imageUrl,
            'reason' => $match['reason'] ?? 'unknown',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'image_url' => $schema->string()->max(2048)->required()->description(
                'A candidate image URL you already saw in image_candidates or a previous recipe execution.',
            ),
        ];
    }
}
