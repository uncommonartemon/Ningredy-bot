<?php

namespace Tests\Unit;

use App\Services\GalleryRecipes\Training\ObservationPresenter;
use Tests\TestCase;

class TrainerPagePayloadTest extends TestCase
{
    public function test_a_progressing_round_is_shown_the_head_of_each_ranked_list(): void
    {
        // Measured on a real call: 109,000 characters, of which three ranked
        // lists carried 72%. The lists arrive ranked, so the tail is what goes.
        $trimmed = $this->scoutForAgent($this->page(), complete: false);

        $this->assertCount(20, $trimmed['image_candidates']);
        $this->assertCount(24, $trimmed['action_candidates']);
        $this->assertCount(16, $trimmed['fragments']);
        // The head, not a sample: the best-ranked entries must survive.
        $this->assertSame('image-0', $trimmed['image_candidates'][0]);
        $this->assertSame('image-19', $trimmed['image_candidates'][19]);
    }

    public function test_the_agent_is_told_how_much_was_left_out(): void
    {
        // Silent trimming would have the agent reason about a page it cannot
        // see all of, without knowing that is what it is doing.
        $trimmed = $this->scoutForAgent($this->page(), complete: false);

        $this->assertSame(30, $trimmed['image_candidates_omitted']);
        $this->assertSame(26, $trimmed['action_candidates_omitted']);
    }

    public function test_a_stalled_round_gets_the_whole_page_back(): void
    {
        // The economy must never be the reason the agent is stuck, so the round
        // after one that made no progress is handed everything.
        $full = $this->scoutForAgent($this->page(), complete: true);

        $this->assertCount(50, $full['image_candidates']);
        $this->assertArrayNotHasKey('image_candidates_omitted', $full);
    }

    public function test_a_short_list_is_passed_through_untouched(): void
    {
        $page = ['image_candidates' => ['only-one'], 'title' => 'Laptop'];

        $this->assertSame($page, $this->scoutForAgent($page, complete: false));
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        return [
            'title' => 'Laptop',
            'image_candidates' => array_map(fn (int $i): string => "image-{$i}", range(0, 49)),
            'action_candidates' => array_map(fn (int $i): string => "action-{$i}", range(0, 49)),
            'fragments' => array_map(fn (int $i): string => "fragment-{$i}", range(0, 31)),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function scoutForAgent(array $page, bool $complete): array
    {
        return (new ObservationPresenter)->scoutForAgent($page, $complete);
    }

    public function test_text_payload_keeps_inline_image_identity_without_sending_binary_as_tokens(): void
    {
        $uri = 'data:image/jpeg;base64,'.base64_encode(str_repeat('binary image', 20000));
        $url = 'https://cdn.example/image?id=front&width=1600';
        $payload = ['diagnostics' => ['raw_dom_samples' => [$url, $uri]],
            'previous_attempt_feedback' => ['images' => [$uri], 'error' => 'click intercepted'],
            'attempt_history' => [['selector' => '.next', 'index' => 46]], 'count' => 0];

        $projected = (new ObservationPresenter)->textPayload($payload);

        $marker = $projected['diagnostics']['raw_dom_samples'][1];
        $this->assertStringContainsString('inline-image', $marker);
        $this->assertStringContainsString('image/jpeg', $marker);
        $this->assertStringContainsString(hash('sha256', $uri), $marker);
        $this->assertSame($marker, $projected['previous_attempt_feedback']['images'][0]);
        $this->assertSame($url, $projected['diagnostics']['raw_dom_samples'][0]);
        $this->assertSame($payload['attempt_history'], $projected['attempt_history']);
        $this->assertSame('click intercepted', $projected['previous_attempt_feedback']['error']);
        $this->assertSame(0, $projected['count']);
        $this->assertLessThan(2000, strlen(json_encode($projected)));
        $this->assertSame($uri, $payload['diagnostics']['raw_dom_samples'][1]);
    }

    public function test_inline_image_inside_html_is_compact_but_controls_and_distinct_assets_survive(): void
    {
        $front = 'data:image/png;base64,'.base64_encode('front image');
        $back = 'data:image/png;base64,'.base64_encode('back image');
        $payload = ['fragments' => ['<img src="'.$front.'"><button class="next">Next</button>'],
            'image_candidates' => [['src' => $front], ['src' => $back]],
            'visible_overlays' => [['text' => 'Accept cookies']]];

        $result = (new ObservationPresenter)->textPayload($payload);

        $this->assertStringNotContainsString($front, $result['fragments'][0]);
        $this->assertStringContainsString('<button class="next">Next</button>', $result['fragments'][0]);
        $this->assertNotSame($result['image_candidates'][0]['src'], $result['image_candidates'][1]['src']);
        $this->assertSame($payload['visible_overlays'], $result['visible_overlays']);
    }
}
