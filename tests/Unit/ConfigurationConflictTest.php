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

    public function test_a_memory_ceiling_beside_installed_memory_does_not_widen_what_is_accepted(): void
    {
        // "32 GB installed, up to 64 GB supported" must not make a 64 GB
        // listing look like the same machine: only the installed line counts.
        $draft = $this->draftWithSpecifications([
            ['key' => 'memory', 'name' => 'Installed memory', 'value' => '32 GB LPDDR5X'],
            ['key' => 'max_memory_capacity', 'name' => 'Memory capacity', 'value' => '64 GB'],
            ['key' => 'memory_slots', 'name' => 'Memory slots', 'value' => 'expandable to 64 GB'],
        ]);

        $this->assertTrue(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $draft,
            ['url' => 'https://shop.example.com/swift-go-14-64-gb-ram'],
        ));
    }

    public function test_graphics_memory_on_the_source_is_not_read_as_system_memory(): void
    {
        // A card's own memory is a power of two in the same range, so counting
        // it would reject a source whose only sin is naming its GPU.
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB'),
            ['url' => 'https://shop.example.com/blade-18-geforce-rtx-5080-16gb'],
        ));
    }

    public function test_graphics_memory_cannot_stand_in_for_the_missing_system_memory(): void
    {
        // The mirror image: a 16 GB machine with a 32 GB card used to satisfy a
        // 32 GB draft, because every size on the page was thrown into one pool.
        $this->assertTrue(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB'),
            ['title' => 'Gaming laptop, 16 GB RAM, RTX 5090 32 GB GDDR7'],
        ));
    }

    public function test_storage_beside_the_installed_size_leaves_it_readable(): void
    {
        // The window around a size stops at the next size, so the SSD's words
        // describe the SSD and the 32 GB in front of it stays system memory.
        $this->assertFalse(app(ProductIdentityMatcher::class)->conflictsConfiguration(
            $this->draftWithMemory('32 GB'),
            ['title' => 'Swift Go 14, 32 GB, 256 GB SSD'],
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
