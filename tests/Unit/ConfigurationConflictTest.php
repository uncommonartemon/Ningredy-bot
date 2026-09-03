<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Services\Products\ProductIdentityMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_listing_for_another_memory_configuration_conflicts(): void
    {
        // Real case (2026-09-03, draft #84): the card committed to 32 GB and its
        // photos came from a shop selling the 16 GB build of the same model. Every
        // identifier check passed, because identifiers are only enforced as far as
        // the operator typed them and the operator named only the model.
        $this->assertTrue(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB LPDDR5X (onboard)'),
            ['url' => 'https://www.cyclotron.de/shop/nx-ksgeg-00n-acer-swift-go-14-sfg14-73-intel-arc-graphics-16-gb-ram-1-024-tb-ssd-nvme-423849'],
        ));
    }

    public function test_a_source_that_says_nothing_about_memory_never_conflicts(): void
    {
        // The common shape: evidence is just a product URL. Silence must stay
        // silence, or the gate would reject most legitimate sources.
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB LPDDR5X'),
            ['url' => 'https://shop.example.com/acer-swift-go-14-sfg14-73'],
        ));
    }

    public function test_the_same_configuration_does_not_conflict(): void
    {
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB LPDDR5X'),
            ['url' => 'https://shop.example.com/swift-go-14-32-gb-ram-2-tb-ssd'],
        ));
    }

    public function test_a_page_offering_several_configurations_is_not_a_conflict(): void
    {
        // A comparison or picker page naming both sizes still contains the one
        // the draft wants, so it stays eligible.
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB'),
            ['title' => 'Acer Swift Go 14 — 16 GB or 32 GB RAM'],
        ));
    }

    public function test_a_draft_without_a_memory_specification_cannot_conflict(): void
    {
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory(null),
            ['url' => 'https://shop.example.com/swift-go-14-16-gb-ram'],
        ));
    }

    public function test_maximum_supported_memory_is_not_the_installed_configuration(): void
    {
        // "Maximum supported memory: 32 GB" describes a ceiling, not what is in
        // the machine, and must not be matched against a source.
        $draft = $this->draftWithSpecifications([
            ['key' => 'maximum_memory', 'name' => 'Maximum supported memory', 'value' => '32 GB'],
        ]);

        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $draft,
            ['url' => 'https://shop.example.com/swift-go-14-16-gb-ram'],
        ));
    }

    private function draftWithMemory(?string $memory): ProductDraft
    {
        return $this->draftWithSpecifications($memory === null ? [] : [
            ['key' => 'memory', 'name' => 'Installed memory', 'value' => $memory],
        ]);
    }

    /** @param array<int, array<string, string>> $specifications */
    private function draftWithSpecifications(array $specifications): ProductDraft
    {
        // The matcher only reads specifications, so the draft never has to exist
        // in the database for this behaviour.
        $draft = new ProductDraft;
        $draft->title = 'Acer Swift Go 14 SFG14-73';
        $draft->model = 'Swift Go 14 SFG14-73';
        $draft->specifications = $specifications;

        return $draft;
    }
}
