<?php

namespace Tests\Feature;

use App\Models\ImageRenditionRule;
use App\Models\ImageRenditionRuleOutcome;
use App\Services\Photos\ImageRenditionProbe;
use App\Services\Photos\RenditionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Operator, 2026-10-27: "if a site changes something, can the agent change the rule itself?" */
class RenditionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RenditionRules::forget();
    }

    public function test_the_rules_are_data_seeded_from_the_file_that_ships_with_the_code(): void
    {
        $seeds = RenditionRules::seeds();

        $this->assertNotEmpty($seeds);
        $this->assertSame(count($seeds), ImageRenditionRule::query()->where('origin', 'seed')->whereNull('host')->count());
        $this->assertSame(array_column($seeds, 'find'), array_column(RenditionRules::forHost('cdn.any-shop.test'), 'find'));
        $this->assertSame('https://cdn.any-shop.test/files/photo.jpg',
            ImageRenditionProbe::largerRenditions('https://cdn.any-shop.test/files/photo_300x.jpg')[0]);
    }

    public function test_a_rule_that_keeps_missing_on_a_host_stops_there_and_nowhere_else(): void
    {
        config(['product-images.rendition_probe.disable_after_misses' => 3]);
        $rule = ImageRenditionRule::query()->where('origin', 'seed')->orderBy('position')->firstOrFail();

        RenditionRules::record($rule->id, 'img.changed-shop.test', false);
        RenditionRules::record($rule->id, 'img.changed-shop.test', true);
        RenditionRules::record($rule->id, 'img.changed-shop.test', false);
        RenditionRules::record($rule->id, 'img.changed-shop.test', false);
        $this->assertContains($rule->id, array_column(RenditionRules::forHost('img.changed-shop.test'), 'id'), 'A hit restarts the count.');

        RenditionRules::record($rule->id, 'img.changed-shop.test', false);

        $this->assertNotContains($rule->id, array_column(RenditionRules::forHost('img.changed-shop.test'), 'id'));
        $this->assertContains($rule->id, array_column(RenditionRules::forHost('img.other-shop.test'), 'id'));
        $this->assertSame('active', $rule->fresh()->status, 'A seed stops on the host that changed, not everywhere.');
        $outcome = ImageRenditionRuleOutcome::query()->where('image_rendition_rule_id', $rule->id)->where('host', 'img.changed-shop.test')->firstOrFail();
        $this->assertSame(1, $outcome->hits);
        $this->assertSame(4, $outcome->misses);
        $this->assertNotNull($outcome->disabled_at);
        $this->assertContains($rule->id, RenditionRules::forBrowser()['disabled']['img.changed-shop.test']);
    }

    public function test_a_site_s_own_rule_comes_first_and_stops_everywhere_when_the_site_changes(): void
    {
        config(['product-images.rendition_probe.disable_after_misses' => 2]);
        $learned = RenditionRules::learn('media.shop.test', '/th/(.+)_w\d{2,4}_h\d{2,4}(\.[a-z]{3,4})$', 'i', '/{1}{2}',
            'Thumbnails live under /th/ with the size in the name.',
            'https://img.media.shop.test/th/product/a_w470_h470.jpg', 'https://img.media.shop.test/product/a.jpg');

        $this->assertNotNull($learned);
        $guesses = ImageRenditionProbe::guesses('https://img.media.shop.test/th/product/4ea/acer_cff15c_w470_h470.jpg');
        $this->assertSame('https://img.media.shop.test/product/4ea/acer_cff15c.jpg', array_key_first($guesses));
        $this->assertSame($learned->id, $guesses[array_key_first($guesses)]);
        $this->assertNotContains($learned->id, array_column(RenditionRules::forHost('img.unrelated.test'), 'id'));

        RenditionRules::record($learned->id, 'img.media.shop.test', false);
        RenditionRules::record($learned->id, 'img.media.shop.test', false);

        $this->assertSame('disabled', $learned->fresh()->status);
        $this->assertNotContains($learned->id, array_column(RenditionRules::forHost('img.media.shop.test'), 'id'));
    }

    public function test_a_rule_learned_again_is_that_rule_again_with_its_misses_forgotten(): void
    {
        config(['product-images.rendition_probe.disable_after_misses' => 2]);
        $learn = fn (): ?ImageRenditionRule => RenditionRules::learn('img.media.shop.test', '/th/(.+)_w\d{2,4}_h\d{2,4}(\.[a-z]{3,4})$', 'i',
            '/{1}{2}', 'Measured larger.', 'https://img.media.shop.test/th/a_w470_h470.jpg', 'https://img.media.shop.test/a.jpg');
        $first = $learn();
        RenditionRules::record($first->id, 'img.media.shop.test', false);
        RenditionRules::record($first->id, 'img.media.shop.test', false);
        $this->assertSame('disabled', $first->fresh()->status);

        // Measured larger again on the site's own frames: the evidence of today wins.
        $again = $learn();

        $this->assertSame($first->id, $again->id);
        $this->assertSame('active', $again->fresh()->status);
        $this->assertSame(1, ImageRenditionRule::query()->where('origin', 'agent')->count());
        $outcome = ImageRenditionRuleOutcome::query()->where('image_rendition_rule_id', $first->id)->first();
        $this->assertSame([0, null], [$outcome->consecutive_misses, $outcome->disabled_at]);
        $this->assertContains($first->id, array_column(RenditionRules::forHost('img.media.shop.test'), 'id'));
    }

    public function test_a_rule_that_cannot_run_is_not_learned(): void
    {
        $this->assertNull(RenditionRules::learn('shop.test', '/(unclosed', '', '/', null, 'https://a.test/x.jpg', 'https://a.test/y.jpg'));
        $this->assertSame(0, ImageRenditionRule::query()->where('origin', 'agent')->count());
    }
}
