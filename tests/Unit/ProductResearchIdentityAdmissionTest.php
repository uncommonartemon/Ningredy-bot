<?php

namespace Tests\Unit;

use App\Services\Products\ProductResearchConfigurationGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductResearchIdentityAdmissionTest extends TestCase
{
    #[DataProvider('publisherTypes')]
    public function test_observed_full_model_can_be_a_candidate_without_ean_or_mpn(string $type): void
    {
        $data = $this->modelOnly($type);
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($data));
        $this->assertArrayNotHasKey('_photo_applicability', $data['sources'][0]);
    }

    public static function publisherTypes(): array
    {
        return [['manufacturer'], ['web'], ['retailer']];
    }

    public function test_family_or_unobserved_model_is_unresolved_instead_of_being_confirmed_by_letters_and_digits(): void
    {
        foreach (['family', 'unknown', 'different_quote', 'other_page', 'missing_assessment', 'compatible_only'] as $case) {
            $data = $this->modelOnly();
            match ($case) {
                'family' => $data['selected_identity']['model_scope'] = 'product_family',
                'unknown' => $data['selected_identity']['model_scope'] = 'unknown',
                'different_quote' => $data['selected_identity']['source_excerpt'] = 'This page shows a Ryzen 9 processor.',
                'other_page' => $data['selected_identity']['source_url'] = 'https://unknown.example/other',
                'missing_assessment' => $data['selected_identity'] = null,
                'compatible_only' => $data['sources'][0]['roles'] = ['compatible_photo_candidate'],
            };
            $this->assertNotEmpty(app(ProductResearchConfigurationGuard::class)->issues($data), $case);
            $this->assertSame('found', $data['status'], 'The guard must not rewrite uncertainty into not_found.');
        }
    }

    public function test_a_kept_label_code_identifies_the_product_when_the_page_model_string_names_the_family(): void
    {
        $data = $this->modelOnly();
        $data['selected_identity']['model_scope'] = 'product_family';
        $this->assertNotEmpty(app(ProductResearchConfigurationGuard::class)->issues($data), 'no code: family scope stays unresolved');

        $data['specifications'][] = ['key' => 'sku', 'value' => 'AWAC16251-9765BLU-PDE'];
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($data));
    }

    public function test_legacy_coded_result_keeps_working_and_alternative_hardware_remains_invalid(): void
    {
        $data = $this->modelOnly();
        unset($data['selected_identity'], $data['sources'][0]['roles']);
        $data['specifications'][] = ['key' => 'mpn', 'value' => '100-100001084WOF'];
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($data));
        $data['specifications'][] = ['key' => 'cpu', 'value' => 'Various processors depending on SKU'];
        $this->assertNotEmpty(app(ProductResearchConfigurationGuard::class)->issues($data));
    }

    public function test_a_shops_punctuation_or_spacing_of_the_model_still_proves_it(): void
    {
        // Live #237: "Name : Dell XPS 13- 9340 ... Color : Graphite" was refused twice for "XPS 13 9340".
        foreach (['Name : Dell XPS 13- 9340 Graphite', 'Dell XPS13 9340 laptop', 'XPS 13-9340, 16 GB'] as $excerpt) {
            $data = $this->modelOnly();
            $data['model'] = 'XPS 13 9340';
            $data['selected_identity']['observed_model'] = 'XPS 13 9340';
            $data['selected_identity']['source_excerpt'] = $excerpt;
            $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($data), $excerpt);
        }
        // Still the whole model, token by token: a shorter or different number proves nothing.
        foreach (['Dell XPS 13 laptops', 'Dell XPS 13 93401 refurbished', 'Dell XPS 9340 13'] as $excerpt) {
            $data = $this->modelOnly();
            $data['model'] = 'XPS 13 9340';
            $data['selected_identity']['observed_model'] = 'XPS 13 9340';
            $data['selected_identity']['source_excerpt'] = $excerpt;
            $this->assertNotEmpty(app(ProductResearchConfigurationGuard::class)->issues($data), $excerpt);
        }
    }

    private function modelOnly(string $type = 'manufacturer'): array
    {
        return ['status' => 'found', 'model' => 'AMD Ryzen 7 9800X3D', 'primary_source_url' => 'https://maker.example/9800x3d',
            'sources' => [['title' => 'AMD Ryzen 7 9800X3D', 'url' => 'https://maker.example/9800x3d', 'type' => $type,
                'roles' => ['facts', 'exact_photo']]], 'specifications' => [],
            'selected_identity' => ['basis' => 'full_model', 'model_scope' => 'single_product',
                'observed_model' => 'AMD Ryzen 7 9800X3D', 'source_url' => 'https://maker.example/9800x3d',
                'source_excerpt' => 'AMD Ryzen 7 9800X3D. 8-core desktop processor.',
                'reason' => 'The observed product page identifies this complete processor model, not a list of alternatives.']];
    }
}
