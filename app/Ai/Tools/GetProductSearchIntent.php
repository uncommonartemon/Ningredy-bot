<?php

namespace App\Ai\Tools;

use App\Models\Category;
use App\Services\Products\ProductRequestContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetProductSearchIntent implements Tool
{
    /**
     * The request is the draft's, handed in by the training loop. It used to
     * be looked up from the run's Telegram update, which on a continued or
     * retrained search is the button press - so asking this tool "what did
     * the operator want?" answered "draft:continue-search:8".
     */
    public function __construct(
        private readonly ProductRequestContext $productRequest,
        private readonly ?string $categorySlug,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get what the operator actually asked for: the original Telegram request text that started this '
            .'draft (or original_operator_request_status=unavailable when no request is bound to this run), the '
            .'product research selected (model, identifiers, color, configuration), the operator\'s current '
            .'retraining hint if any, and this product category\'s own search hint (if any). Use this when DOM '
            .'evidence is ambiguous about which variant, color or configuration is wanted, instead of guessing.';
    }

    public function handle(Request $request): Stringable|string
    {
        $categoryHint = $this->categorySlug !== null
            ? Category::query()->where('slug', $this->categorySlug)->first()?->product_search_hint
            : null;

        return json_encode([
            ...$this->productRequest->forAgent(),
            'category_search_hint' => is_string($categoryHint) && trim($categoryHint) !== ''
                ? trim($categoryHint)
                : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
