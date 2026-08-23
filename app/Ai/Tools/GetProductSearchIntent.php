<?php

namespace App\Ai\Tools;

use App\Models\Category;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetProductSearchIntent implements Tool
{
    public function __construct(
        private readonly ?int $telegramUpdateId,
        private readonly ?string $categorySlug,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get what the operator actually asked for: the original Telegram request text that triggered '
            .'this search (if any) and this product category\'s own search hint (if any). Use this when DOM '
            .'evidence is ambiguous about which variant, color or configuration is wanted, instead of guessing.';
    }

    public function handle(Request $request): Stringable|string
    {
        $requestText = $this->telegramUpdateId !== null
            ? TelegramUpdate::query()->find($this->telegramUpdateId)?->text
            : null;
        $categoryHint = $this->categorySlug !== null
            ? Category::query()->where('slug', $this->categorySlug)->first()?->product_search_hint
            : null;

        return json_encode([
            'telegram_request_text' => is_string($requestText) && trim($requestText) !== ''
                ? mb_substr(trim($requestText), 0, 1000)
                : null,
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
