<?php

namespace Tests\Unit;

use App\Ai\Tools\ReadGalleryPageObservation;
use App\Services\GalleryRecipes\Training\ObservationPresenter;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class GalleryObservationFocusTest extends TestCase
{
    public function test_expanding_the_full_snapshot_does_not_reintroduce_binary_image_tokens(): void
    {
        $uri = 'data:image/jpeg;base64,'.base64_encode(str_repeat('image bytes', 20000));
        $original = ['image_candidates' => [['src' => $uri, 'selector' => '#main-photo']],
            'action_candidates' => [['selector' => '#close-cookie-banner']]];
        $tool = new ReadGalleryPageObservation($original, $original);

        foreach (['initial', 'latest'] as $snapshot) {
            $result = json_decode($tool->handle(new Request(['snapshot' => $snapshot, 'section' => 'all'])), true);
            $this->assertLessThan(2000, strlen(json_encode($result)));
            $this->assertNotSame($uri, $result['observation']['image_candidates'][0]['src']);
            $this->assertStringContainsString(hash('sha256', $uri), json_encode($result));
            $this->assertSame($original['action_candidates'], $result['observation']['action_candidates']);
        }
        $this->assertSame($uri, $original['image_candidates'][0]['src']);
    }

    public function test_a_fresh_wide_observation_does_not_duplicate_the_old_full_dom_in_the_prompt(): void
    {
        $presenter = new ObservationPresenter;
        $initial = ['title' => 'Laptop', 'fragments' => [str_repeat('Original HTML ', 5000)]];
        $latest = ['fragments' => ['New dialog and gallery'], 'observation_focus' => ['mode' => 'page']];
        $short = $presenter->initialObservationForAgent($initial, ['previous_attempt_observation' => $latest], false);
        $this->assertArrayNotHasKey('fragments', $short);
        $tool = new ReadGalleryPageObservation($initial, $latest);
        $read = json_decode($tool->handle(new Request(['snapshot' => 'initial'])), true);
        $this->assertSame($initial, $read['observation']);
        $this->assertSame($initial, $presenter->initialObservationForAgent($initial, null, false));
    }

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
        $presenter = new ObservationPresenter;
        $initial = ['title' => 'Laptop', 'final_url' => 'https://shop.example/a', 'fragments' => ['original DOM']];
        $feedback = ['previous_attempt_observation' => ['observation_focus' => ['mode' => 'focused']]];
        $short = $presenter->initialObservationForAgent($initial, $feedback, false);
        $this->assertSame('Laptop', $short['title']);
        $this->assertArrayNotHasKey('fragments', $short);
        $this->assertSame($initial, $presenter->initialObservationForAgent($initial, $feedback, true));
        $this->assertSame($initial, $presenter->initialObservationForAgent($initial, null, false));
    }

    public function test_full_snapshot_stays_available_to_tool_but_out_of_focused_prompt(): void
    {
        $presenter = new ObservationPresenter;
        $wide = ['fragments' => ['outside controls']];
        $source = ['fragments' => ['viewer'], 'full_page_observation' => $wide];
        $this->assertSame(['fragments' => ['viewer']], $presenter->observationForAgent($source));
        $this->assertSame($wide, $source['full_page_observation']);
        $tool = new ReadGalleryPageObservation(['title' => 'initial'], $wide);
        $latest = json_decode($tool->handle(new Request(['snapshot' => 'latest'])), true);
        $initial = json_decode($tool->handle(new Request(['snapshot' => 'initial'])), true);
        $this->assertSame($wide, $latest['observation']);
        $this->assertSame('initial', $initial['observation']['title']);
    }
}
