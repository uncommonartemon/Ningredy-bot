<?php

namespace App\Ai\Tools;

use App\Models\ProductSourcePageEvidence;
use App\Services\Photos\ImageUrls;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * An optional, free side door for the assembly agent: which product a code
 * belongs to, with official specifications when Icecat knows it. A found
 * datasheet is saved as an observed page so it can be cited - or ignored.
 */
class LookupProductCode implements Tool
{
    public function __construct(
        private readonly ?int $telegramUpdateId = null,
        private readonly ?int $productDraftId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Look up an EAN/UPC barcode, or a part number with its brand, in structured product databases '
            .'(Icecat: official specifications and gallery for many brands; UPCitemdb: barcode to brand and model). '
            .'Use it to check which product an operator-supplied or page code belongs to, or to read official '
            .'specifications. Results are hints, not verification: compare brand, code and configuration with the '
            .'selected product, and ignore them when they describe something else. A found Icecat datasheet becomes '
            .'an observed page you may cite by its page_url. Free. Recent track record: '
            .json_encode(app(ProductCodeLookup::class)->trackRecord());
    }

    public function handle(Request $request): Stringable|string
    {
        $code = trim((string) $request->string('code'));
        $brand = trim((string) $request->string('brand'));
        if ($code === '') {
            return json_encode(['results' => [], 'note' => 'code is required']);
        }
        try {
            $gtins = ProductCodeLookup::gtins($code);
            $results = app(ProductCodeLookup::class)->lookup($gtins, $brand !== '' ? $brand : null,
                $gtins === [] ? [$code] : [], $this->telegramUpdateId, 'card_assembly_tool');
        } catch (Throwable $exception) {
            report($exception);

            return json_encode(['results' => [], 'note' => 'lookup unavailable; continue without it']);
        }
        foreach ($results as $result) {
            if ($result['found'] && $result['source'] === 'icecat' && $this->productDraftId !== null && filled($result['page_url'] ?? null)) {
                ProductSourcePageEvidence::query()->updateOrCreate(
                    ['product_draft_id' => $this->productDraftId, 'url_hash' => hash('sha256', ImageUrls::normalizeCandidateUrl($result['page_url']))],
                    ['url' => $result['page_url'], 'specification_text' => implode("\n", [$result['title'], ...$result['specifications']]),
                        'captured_via' => 'icecat'],
                );
            }
        }

        return json_encode(['results' => array_map(fn (array $result): array => [
            ...array_intersect_key($result, array_flip(['source', 'code', 'status', 'title', 'brand', 'part_number', 'gtins', 'page_url', 'color', 'description'])),
            'specifications' => array_slice((array) ($result['specifications'] ?? []), 0, 60),
        ], $results)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->max(100)->required()->description('An EAN/UPC barcode or a manufacturer part number.'),
            'brand' => $schema->string()->max(100)->nullable()->required()->description('The brand for a part number; null for a barcode.'),
        ];
    }
}
