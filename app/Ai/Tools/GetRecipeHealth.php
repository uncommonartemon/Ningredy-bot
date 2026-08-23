<?php

namespace App\Ai\Tools;

use App\Models\ProductGalleryRecipe;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetRecipeHealth implements Tool
{
    public function __construct(private readonly string $domain) {}

    public function description(): Stringable|string
    {
        return 'Check this domain\'s own recipe status and failure/degrade history before assuming a failure is '
            .'new. A domain already close to being disabled, or already cycling through retrains because of a '
            .'download-layer problem (not a selector problem), needs a different response than a first-time '
            .'failure.';
    }

    public function handle(Request $request): Stringable|string
    {
        $recipe = ProductGalleryRecipe::query()->where('domain', $this->domain)->first();

        if (! $recipe) {
            return json_encode(['found' => false], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'found' => true,
            'status' => $recipe->status,
            'failure_count' => $recipe->failure_count,
            'consecutive_hard_blocks' => $recipe->consecutive_hard_blocks,
            'last_failure_kind' => $recipe->last_failure_kind,
            'last_error' => $recipe->last_error,
            'last_success_at' => $recipe->last_success_at?->toIso8601String(),
            'last_failure_at' => $recipe->last_failure_at?->toIso8601String(),
            'retry_after' => $recipe->retry_after?->toIso8601String(),
            'success_count' => $recipe->success_count,
            'download_degrade_cycles' => app(ProductGalleryRecipeTrainer::class)->downloadDegradeCycles($this->domain),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
