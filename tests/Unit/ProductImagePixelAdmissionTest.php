<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductSourcePageMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ProductImagePixelAdmissionTest extends TestCase
{
    #[DataProvider('unsupportedSources')]
    public function test_unknown_revision_does_not_bypass_conflicts_or_requirements_for_observed_main_product(string $scenario): void
    {
        $draft = new ProductDraft(['model' => 'Ryzen 7 9800X3D',
            'specifications' => [['key' => 'mpn', 'value' => '100-100001084WOF']]]);
        $source = ['url' => 'https://shop.example/cpu',
            '_preflight_page_identity' => ['observed_url' => 'https://shop.example/cpu',
                'product' => ['mpn' => '100-100001084WOF']],
            '_photo_applicability' => ['verdict' => 'uncertain', 'source_model' => 'AMD Ryzen 7 9800X3D BOX',
                'plausible_target' => true, 'may_inspect_gallery' => true,
                'physical_checks' => ['platform' => ['status' => 'match'], 'revision' => ['status' => 'unknown'],
                    'construction' => ['status' => 'match'], 'color' => ['status' => 'unknown']]],
        ];
        if (in_array($scenario, ['revision', 'construction', 'color'], true)) {
            $source['_photo_applicability']['physical_checks'][$scenario]['status'] = 'conflicting';
        } elseif ($scenario === 'mismatched code') {
            $source['_preflight_page_identity']['product']['mpn'] = '100-100001084WOF-X';
        } elseif ($scenario === 'missing metadata') {
            unset($source['_preflight_page_identity']);
        } elseif ($scenario === 'another page') {
            $source['_preflight_page_identity']['observed_url'] = 'https://shop.example/another-cpu';
        } else {
            $source['_preflight_page_identity'] = ProductSourcePageMetadata::fromHtml(
                '<script type="application/ld+json">{"@type":"Product","name":"Other CPU","mpn":"OTHER",'
                .'"isRelatedTo":{"@type":"Product","mpn":"100-100001084WOF"}}</script>', $source['url']);
        }

        $storage = app(ProductImageStorage::class);
        $this->assertFalse((new ReflectionMethod($storage, 'modelAwaitingPixelInspection'))->invoke($storage, $draft, $source));
        $this->assertFalse((new ReflectionMethod($storage, 'modelConfirmedAwaitingColour'))->invoke($storage, $source));
    }

    public static function unsupportedSources(): array
    {
        return array_combine($cases = ['revision', 'construction', 'color', 'mismatched code',
            'missing metadata', 'another page', 'recommendations only'], array_map(fn (string $case): array => [$case], $cases));
    }
}
