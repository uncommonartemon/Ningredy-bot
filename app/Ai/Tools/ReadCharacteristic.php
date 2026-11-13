<?php

namespace App\Ai\Tools;

use App\Services\Catalog\AttributeDictionary;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** Lets the dictionary's reviewer look into one characteristic before it decides (decision of 2026-11-13). */
class ReadCharacteristic implements Tool
{
    public function description(): Stringable|string
    {
        return 'Everything about one characteristic of the catalog: every value as written with how often it occurs, the products '
            .'that carry it with their categories, its names in other languages and other names, the categories that use or '
            .'require it, and which characteristics stand on the same products (on_the_same_products_as: key => products).';
    }

    public function handle(Request $request): Stringable|string
    {
        $detail = app(AttributeDictionary::class)->detail((string) ($request['key'] ?? ''));

        return (string) json_encode($detail ?? ['error' => 'No characteristic with this key.'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['key' => $schema->string()->max(255)->required()];
    }
}
