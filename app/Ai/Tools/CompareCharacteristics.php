<?php

namespace App\Ai\Tools;

use App\Services\Catalog\AttributeDictionary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** Lets the dictionary's reviewer put characteristics side by side before it proposes a merge (decision of 2026-11-13). */
class CompareCharacteristics implements Tool
{
    public function description(): Stringable|string
    {
        return 'Two to five characteristics side by side: how many products carry each, how many carry more than one of them, '
            .'on how many those values differ, and the values side by side on up to 25 such products. A product that carries '
            .'two of them at different values had them as two different properties.';
    }

    public function handle(Request $request): Stringable|string
    {
        $keys = array_slice(array_values(array_filter((array) ($request['keys'] ?? []), 'is_string')), 0, 5);

        return (string) json_encode(app(AttributeDictionary::class)->compare($keys),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['keys' => $schema->array()->items($schema->string()->max(255))->min(2)->max(5)->required()];
    }
}
