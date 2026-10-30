<?php

namespace Tests\Unit;

use App\Services\Products\ProductResearchConfigurationGuard;
use Tests\TestCase;

class ProductResearchConfigurationGuardTest extends TestCase
{
    public function test_it_rejects_a_family_with_alternative_hardware(): void
    {
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => 'Titan 18 HX AI A2XW',
            'primary_source_url' => 'https://manufacturer.example/titan',
            'sources' => [[
                'url' => 'https://manufacturer.example/titan',
                'type' => 'manufacturer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => 'A2XW family; variants include A2XWJG and A2XWIG'],
                ['key' => 'gpu', 'value' => 'RTX 5090 or RTX 5080, varies by SKU'],
            ],
        ]);

        $this->assertNotEmpty($issues);
        $this->assertTrue(collect($issues)->contains(fn (string $issue): bool => str_starts_with($issue, 'gpu ')));
    }

    public function test_it_does_not_reject_ordinary_single_sku_spec_phrasing(): void
    {
        // Live evidence: this over-broad regex previously rejected a real
        // ASUS ROG Strix SCAR 18 and HP OMEN 16 Max search over completely
        // ordinary single-SKU spec wording - "boost up to 5.4 GHz", "up to
        // 240Hz refresh rate" describe how one fixed component behaves, not
        // that several different components could be installed.
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => 'G835LX-XS97',
            'primary_source_url' => 'https://shop.example/g835lx',
            'sources' => [[
                'url' => 'https://shop.example/g835lx',
                'type' => 'retailer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => 'G835LX-XS97'],
                ['key' => 'cpu', 'value' => 'Intel Core Ultra 9 275HX (up to 5.4 GHz, 24 cores)'],
                ['key' => 'gpu', 'value' => 'NVIDIA GeForce RTX 5090 Laptop GPU (24 GB GDDR7, ROG Boost up to 175W)'],
                ['key' => 'refresh_rate', 'value' => 'Up to 240 Hz'],
                ['key' => 'display', 'value' => '18.0" WQXGA mini-LED, HDR support (SKU-dependent peak brightness up to ~1200 nits reported)'],
            ],
        ]);

        $this->assertSame([], $issues);
    }

    public function test_it_accepts_one_exact_retail_configuration(): void
    {
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => '82XT003GUS',
            'primary_source_url' => 'https://shop.example/82xt003gus',
            'sources' => [[
                'url' => 'https://shop.example/82xt003gus',
                'type' => 'retailer',
            ]],
            'specifications' => [
                ['key' => 'sku', 'value' => '82XT003GUS'],
                ['key' => 'gpu', 'value' => 'NVIDIA GeForce RTX 3050 6GB'],
                ['key' => 'ram', 'value' => '16GB DDR5-5600'],
            ],
        ]);

        $this->assertSame([], $issues);
    }

    public function test_a_configure_to_order_line_is_one_product_once_a_configuration_is_chosen(): void
    {
        // Live #317: Framework sells the Laptop 13 only through its configurator - no
        // code, no suffix for the whole machine. The research named the line and the
        // configurator three times, was asked for a full model three times, and the
        // request died without a draft.
        $line = 'Framework Laptop 13 (AMD Ryzen 7040 Series)';
        $page = 'https://maker.example/laptop-13/configure';
        $answer = [
            'status' => 'found',
            'model' => $line.' - Ryzen 7 7840U, 32 GB DDR5, 1 TB SSD',
            'primary_source_url' => $page,
            'sources' => [['url' => $page, 'type' => 'manufacturer', 'roles' => ['facts', 'exact_photo']]],
            'selected_identity' => ['basis' => 'configured_product', 'model_scope' => 'single_product', 'observed_model' => $line,
                'source_url' => $page, 'source_excerpt' => 'Framework Laptop 13 (AMD Ryzen 7040 Series) - configure your laptop: processor, memory, storage',
                'reason' => 'Configure-to-order: the page names the line and offers these options; no catalog code exists for the whole machine.'],
            'specifications' => [
                ['key' => 'cpu', 'value' => 'AMD Ryzen 7 7840U'],
                ['key' => 'ram', 'value' => '32 GB DDR5-5600'],
                ['key' => 'storage', 'value' => '1 TB NVMe SSD'],
            ],
        ];

        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($answer));

        // The line alone, without the options that pin one configuration, is still a family.
        $family = [...$answer, 'model' => $line, 'specifications' => []];
        $issues = app(ProductResearchConfigurationGuard::class)->issues($family);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('The selected identity is unresolved', $issues[0]);
        $this->assertStringContainsString('configured_product', $issues[0]);

        // And the page must name the line it is said to name.
        $unproven = [...$answer, 'selected_identity' => [...$answer['selected_identity'], 'source_excerpt' => 'Configure your laptop']];
        $this->assertCount(1, app(ProductResearchConfigurationGuard::class)->issues($unproven));

        // Live #369: the line's platform words name a series ("AMD Ryzen™ 7040 Series"); the
        // chosen configuration names the concrete processor in their place. The family before
        // them is what the page proves; the options are proven by the specifications.
        $refurbishedLine = 'Framework Laptop 13 DIY Edition (AMD Ryzen™ 7040 Series)';
        $chosen = [...$answer,
            'model' => 'Framework Laptop 13 DIY Edition (AMD Ryzen 7 7840U, 2.8K display, RAM not included, storage not included)',
            'selected_identity' => [...$answer['selected_identity'], 'observed_model' => $refurbishedLine,
                'source_excerpt' => 'Refurbished '.$refurbishedLine],
            'specifications' => [['key' => 'cpu', 'value' => 'AMD Ryzen 7 7840U'], ['key' => 'gpu', 'value' => 'AMD Radeon 780M integrated graphics'],
                ['key' => 'display', 'value' => '2.8K matte display'], ['key' => 'ram', 'value' => 'Not included']]];
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($chosen));

        // Live #370: the page's line starts with the offer's own word ("Refurbished …"); the
        // model rightly leaves it out and begins with the line from its second token.
        $offerWord = [...$chosen, 'model' => 'Framework Laptop 13 DIY Edition (AMD Ryzen 7040 Series), Ryzen 7 7840U, 2.8K display',
            'selected_identity' => [...$chosen['selected_identity'], 'observed_model' => 'Refurbished '.$refurbishedLine]];
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($offerWord));

        // A model of another family under the same line is still unresolved.
        $otherFamily = [...$chosen, 'model' => 'Framework Laptop 16 (AMD Ryzen 7 7840HS, 2.5K display)'];
        $this->assertCount(1, app(ProductResearchConfigurationGuard::class)->issues($otherFamily));
    }

    public function test_a_sibling_configuration_lends_its_page_but_not_its_codes(): void
    {
        // Live #206: the label said 14-fm0001nn / B2VK4EA#BED, no page showed them,
        // and the research wrote the observed sibling's 14-fm0001na / BG3E7EA#ABU
        // as sku, mpn and model - the operator's card described another product.
        $request = "HP OmniBook X Flip 14-fm0001nn\nP/N: B2VK4EA#BED\nS/N: 5CD5231ABC";
        $sibling = [
            'status' => 'found',
            'model' => '14-fm0001na',
            'unconfirmed_operator_codes' => ['B2VK4EA#BED'],
            'primary_source_url' => 'https://shop.example/14-fm0001na',
            'sources' => [['url' => 'https://shop.example/14-fm0001na', 'type' => 'retailer']],
            'specifications' => [
                ['key' => 'sku', 'value' => '14-fm0001na'],
                ['key' => 'mpn', 'value' => 'BG3E7EA#ABU'],
                ['key' => 'ram', 'value' => '16 GB'],
            ],
        ];

        $issues = app(ProductResearchConfigurationGuard::class)->issues($sibling, $request);

        $this->assertCount(4, $issues);
        $this->assertStringContainsString('Operator code B2VK4EA#BED is unconfirmed but missing', $issues[0]);
        $this->assertStringContainsString('sku holds 14-fm0001na', $issues[1]);
        $this->assertStringContainsString('mpn holds BG3E7EA#ABU', $issues[2]);
        $this->assertStringContainsString('model is 14-fm0001na', $issues[3]);

        // The repaired answer: the operator's codes as their label facts, the sibling in the notes.
        $repaired = [...$sibling, 'model' => '14-fm0001nn', 'unconfirmed_operator_codes' => ['14-fm0001nn', 'B2VK4EA#BED'],
            'research_notes' => 'Closest observed sibling: 14-fm0001na (BG3E7EA#ABU) at shop.example.',
            'specifications' => [
                ['key' => 'sku', 'value' => '14-fm0001nn'],
                ['key' => 'mpn', 'value' => 'B2VK4EA#BED'],
                ['key' => 'ram', 'value' => '16 GB'],
            ]];

        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($repaired, $request));
    }

    public function test_an_unobserved_barcode_alone_leaves_the_observed_model_in_place(): void
    {
        // An EAN names no product line: the page's full model stands, the barcode stays a label fact.
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => '82XT003GUS',
            'unconfirmed_operator_codes' => ['0197528721569'],
            'primary_source_url' => 'https://shop.example/82xt003gus',
            'sources' => [['url' => 'https://shop.example/82xt003gus', 'type' => 'retailer']],
            'specifications' => [
                ['key' => 'sku', 'value' => '82XT003GUS'],
                ['key' => 'ean', 'value' => '0197528721569'],
            ],
        ], 'Lenovo Legion 5 EAN 0197528721569');

        $this->assertSame([], $issues);
    }

    public function test_confirmed_codes_are_not_checked_against_the_request_wording(): void
    {
        // Every code was observed: the research names the product as the pages do.
        $issues = app(ProductResearchConfigurationGuard::class)->issues([
            'status' => 'found',
            'model' => '21M7CTO1WW',
            'unconfirmed_operator_codes' => [],
            'primary_source_url' => 'https://shop.example/e14',
            'sources' => [['url' => 'https://shop.example/e14', 'type' => 'retailer']],
            'specifications' => [['key' => 'sku', 'value' => '21M7CTO1WW']],
        ], 'Lenovo ThinkPad E14 Gen 6 21M7');

        $this->assertSame([], $issues);
    }
}
