<?php

namespace Tests\Unit;

use App\Services\Products\ProductGalleryRecipeTrainer;
use ReflectionMethod;
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
        $trainer = app(ProductGalleryRecipeTrainer::class);

        return (new ReflectionMethod($trainer, 'scoutForAgent'))->invoke($trainer, $page, $complete);
    }
}
