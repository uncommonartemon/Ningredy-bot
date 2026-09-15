<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionClass;
use Tests\TestCase;

class GalleryLayerProgressTest extends TestCase
{
    public function test_intermediate_zoom_is_progress_even_when_final_dom_is_unchanged(): void
    {
        $reflection = new ReflectionClass(ProductGalleryRecipeTrainer::class);
        $trainer = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('trainingProgressSignature');
        $result = ['post_interaction_scout' => ['layer_observations' => [[
            'action_candidates' => [['selector' => '.gallery .zoom']],
            'image_candidates' => [['natural_width' => 584, 'natural_height' => 584]],
        ]]]];
        $first = $method->invoke($trainer, $result);
        $result['post_interaction_scout']['layer_observations'][0]['image_candidates'][0]['natural_width'] = 1200;
        $second = $method->invoke($trainer, $result);
        $this->assertNotSame($first, $second);
        $result['post_interaction_scout']['layer_observations'][0]['timer'] = microtime(true);
        $this->assertSame($second, $method->invoke($trainer, $result));
    }
}
