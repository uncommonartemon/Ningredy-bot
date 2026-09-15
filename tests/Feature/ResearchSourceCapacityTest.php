<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Tools\ResearchProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ResearchSourceCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_sources_survive_normalization_and_server_validation(): void
    {
        $tool = (new ReflectionClass(ResearchProduct::class))->newInstanceWithoutConstructor();
        $response = [
            'status' => 'not_found', 'specifications' => [], 'image_urls' => [], 'confidence' => 0.5,
            'sources' => array_map(fn ($n) => [
                'title' => 'Candidate '.$n, 'url' => 'https://shop'.$n.'.example/product?id='.$n,
                'type' => 'retailer', 'image_urls' => [],
            ], range(1, ProductResearchAgent::MAX_SOURCES)),
        ];
        $result = (new ReflectionMethod(ResearchProduct::class, 'validateCorrectedResearchResponse'))->invoke($tool, $response);
        $this->assertCount(ProductResearchAgent::MAX_SOURCES, $result['sources']);
        $this->assertSame($response['sources'][49]['url'], $result['sources'][49]['url']);
    }
}
