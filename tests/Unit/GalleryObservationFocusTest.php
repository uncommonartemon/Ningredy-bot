<?php

namespace Tests\Unit;

use App\Ai\Tools\ReadGalleryPageObservation;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class GalleryObservationFocusTest extends TestCase
{
    public function test_agent_can_read_just_one_slice_without_receiving_the_whole_page(): void
    {
        $tool = new ReadGalleryPageObservation([], ['fragments' => ['large unrelated text'],
            'action_candidates' => [['selector' => '#one'], ['selector' => '#two'], ['selector' => '#three']]]);
        $result = json_decode($tool->handle(new Request(['snapshot' => 'latest',
            'section' => 'action_candidates', 'offset' => 1, 'limit' => 1])), true);
        $this->assertSame([['selector' => '#two']], $result['observation']['items']);
        $this->assertSame(3, $result['observation']['total']);
        $this->assertArrayNotHasKey('fragments', $result['observation']);
    }

    public function test_focused_prompt_keeps_original_identity_but_not_original_dom(): void
    {
        $trainer = (new \ReflectionClass(ProductGalleryRecipeTrainer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($trainer, 'initialObservationForAgent');
        $initial = ['title' => 'Laptop', 'final_url' => 'https://shop.example/a', 'fragments' => ['original DOM']];
        $feedback = ['previous_attempt_observation' => ['observation_focus' => ['mode' => 'focused']]];
        $short = $method->invoke($trainer, $initial, $feedback, false);
        $this->assertSame('Laptop', $short['title']);
        $this->assertArrayNotHasKey('fragments', $short);
        $this->assertSame($initial, $method->invoke($trainer, $initial, $feedback, true));
        $this->assertSame($initial, $method->invoke($trainer, $initial, null, false));
    }

    public function test_full_snapshot_stays_available_to_tool_but_out_of_focused_prompt(): void
    {
        $trainer = (new \ReflectionClass(ProductGalleryRecipeTrainer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($trainer, 'observationForAgent');
        $wide = ['fragments' => ['outside controls']];
        $source = ['fragments' => ['viewer'], 'full_page_observation' => $wide];
        $this->assertSame(['fragments' => ['viewer']], $method->invoke($trainer, $source));
        $this->assertSame($wide, $source['full_page_observation']);
        $tool = new ReadGalleryPageObservation(['title' => 'initial'], $wide);
        $latest = json_decode($tool->handle(new Request(['snapshot' => 'latest'])), true);
        $initial = json_decode($tool->handle(new Request(['snapshot' => 'initial'])), true);
        $this->assertSame($wide, $latest['observation']);
        $this->assertSame('initial', $initial['observation']['title']);
    }
}
