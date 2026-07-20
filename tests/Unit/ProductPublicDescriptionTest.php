<?php

namespace Tests\Unit;

use App\Services\Products\ProductPublicDescription;
use Tests\TestCase;

class ProductPublicDescriptionTest extends TestCase
{
    public function test_it_keeps_clean_storefront_copy(): void
    {
        $result = app(ProductPublicDescription::class)->normalize([
            'title' => 'Lenovo Legion Pro 5',
            'description' => '**Lenovo Legion Pro 5** is a 16-inch gaming laptop with an RTX 5070 GPU.',
        ]);

        $this->assertSame(
            'Lenovo Legion Pro 5 is a 16-inch gaming laptop with an RTX 5070 GPU.',
            $result['description'],
        );
        $this->assertNull($result['research_notes']);
    }

    public function test_it_moves_research_reasoning_out_of_the_public_description(): void
    {
        $original = "Closest current product found for the user's request. This is a family-level match rather than one exact SKU.";
        $result = app(ProductPublicDescription::class)->normalize([
            'title' => 'Lenovo Legion Pro 5 Gen 10',
            'model' => '16ADR10',
            'color' => 'Eclipse Black',
            'description' => $original,
            'specifications' => [
                ['name' => 'CPU', 'value' => 'AMD Ryzen 9'],
                ['name' => 'GPU', 'value' => 'NVIDIA GeForce RTX 5070'],
            ],
        ]);

        $this->assertStringNotContainsString('Closest', $result['description']);
        $this->assertStringNotContainsString('request', $result['description']);
        $this->assertStringContainsString('CPU: AMD Ryzen 9', $result['description']);
        $this->assertStringContainsString($original, (string) $result['research_notes']);
    }
}
