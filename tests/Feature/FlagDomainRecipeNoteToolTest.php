<?php

namespace Tests\Feature;

use App\Ai\Tools\FlagDomainRecipeNote;
use App\Models\ProductSourceDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class FlagDomainRecipeNoteToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_appends_a_note_to_an_empty_hint(): void
    {
        $domain = ProductSourceDomain::query()->create(['domain' => 'example.com']);

        $result = json_decode(
            (string) (new FlagDomainRecipeNote($domain, null))->handle(new ToolRequest([
                'note' => 'The viewer only opens via .zoom-btn, not .gallery-btn.',
                'category' => 'selector_unreliable',
            ])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($result['ok']);
        $domain->refresh();
        $this->assertStringContainsString('[auto selector_unreliable', $domain->auto_agent_hint);
        $this->assertStringContainsString('The viewer only opens via .zoom-btn, not .gallery-btn.', $domain->auto_agent_hint);
    }

    public function test_it_never_touches_an_existing_human_authored_line(): void
    {
        $domain = ProductSourceDomain::query()->create([
            'domain' => 'example.com',
            'agent_hint' => 'Always accept the cookie banner first.',
        ]);

        (new FlagDomainRecipeNote($domain, null))->handle(new ToolRequest([
            'note' => 'Thumbnail rail is a duplicate of the main slider.',
            'category' => 'other',
        ]));

        $domain->refresh();
        $this->assertStringContainsString('Always accept the cookie banner first.', $domain->agent_hint);
        $this->assertStringContainsString('Thumbnail rail is a duplicate of the main slider.', $domain->auto_agent_hint);
    }

    public function test_auto_notes_are_capped_and_the_oldest_is_dropped(): void
    {
        $domain = ProductSourceDomain::query()->create(['domain' => 'example.com']);

        foreach (range(1, 6) as $index) {
            (new FlagDomainRecipeNote($domain, null))->handle(new ToolRequest([
                'note' => "Auto note number {$index}.",
                'category' => 'other',
            ]));
            $domain->refresh();
        }

        $this->assertStringNotContainsString('Auto note number 1.', $domain->auto_agent_hint);
        $this->assertStringContainsString('Auto note number 2.', $domain->auto_agent_hint);
        $this->assertStringContainsString('Auto note number 6.', $domain->auto_agent_hint);
        $this->assertSame(5, substr_count($domain->auto_agent_hint, '[auto '));
    }

    public function test_it_records_an_audited_operation(): void
    {
        $domain = ProductSourceDomain::query()->create(['domain' => 'example.com']);

        (new FlagDomainRecipeNote($domain, null))->handle(new ToolRequest([
            'note' => 'Note.',
            'category' => 'navigation_hazard',
        ]));

        $this->assertDatabaseHas('ai_operations', [
            'tool' => 'FlagDomainRecipeNote',
            'action' => 'flag_domain_recipe_note',
            'status' => 'completed',
            'target_type' => ProductSourceDomain::class,
            'target_id' => $domain->id,
        ]);
    }

    public function test_an_empty_note_is_rejected(): void
    {
        $domain = ProductSourceDomain::query()->create(['domain' => 'example.com']);

        $this->expectException(\RuntimeException::class);

        (new FlagDomainRecipeNote($domain, null))->handle(new ToolRequest(['note' => '   ']));
    }
}
