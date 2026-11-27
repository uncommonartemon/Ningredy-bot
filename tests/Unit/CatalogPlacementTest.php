<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Catalog\CatalogPlacement;
use App\Services\Catalog\CategoryPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One placement rule on the server (decision of 2026-11-25), for the search's answer and the
 * approval alike: a node that fits stays, the rest goes to the home of the product type and the
 * placement says why. Before, a resolver did the same silently.
 */
class CatalogPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_kind_that_fits_the_type_stays_and_closes_the_question_of_the_kind(): void
    {
        $placement = app(CatalogPlacement::class)->place('component', 'ram');

        $this->assertSame(['ram', CategoryPlacement::CHOSEN, false], [$placement->slug(), $placement->reason, $placement->awaitsKind()]);
    }

    public function test_a_parent_with_kinds_stays_and_says_its_kind_is_open(): void
    {
        $placement = app(CatalogPlacement::class)->place('component', 'components');

        $this->assertSame(['components', CategoryPlacement::CHOSEN, true], [$placement->slug(), $placement->reason, $placement->awaitsKind()]);
        $this->assertContains('Оперативная память', $placement->kinds);
        $this->assertSame(['requested' => 'components', 'placed' => 'components', 'product_type' => 'component',
            'reason' => CategoryPlacement::CHOSEN, 'awaits_kind' => true], $placement->record());
    }

    public function test_nothing_chosen_goes_to_the_home_of_the_type_and_says_so(): void
    {
        $placement = app(CatalogPlacement::class)->place('component', null);

        $this->assertSame(['components', CategoryPlacement::NONE_CHOSEN, true], [$placement->slug(), $placement->reason, $placement->awaitsKind()]);
        $this->assertTrue($placement->settledByServer());
    }

    public function test_a_category_of_another_type_is_replaced_by_the_home_of_the_product_type(): void
    {
        Category::query()->where('slug', 'laptops')->update(['product_type_affinity' => 'laptop']);
        Category::query()->where('slug', 'computers')->update(['product_type_affinity' => 'desktop']);

        $laptop = app(CatalogPlacement::class)->place('laptop', 'computers');
        $part = app(CatalogPlacement::class)->place('component', 'laptops');

        $this->assertSame(['laptops', CategoryPlacement::TYPE_CONFLICT, false], [$laptop->slug(), $laptop->reason, $laptop->awaitsKind()]);
        $this->assertSame(['components', CategoryPlacement::TYPE_CONFLICT, true], [$part->slug(), $part->reason, $part->awaitsKind()]);
        $this->assertSame('laptops', $part->record()['requested']);
    }

    public function test_an_unknown_slug_is_named_and_the_product_goes_to_the_home_of_its_type(): void
    {
        $placement = app(CatalogPlacement::class)->place('laptop', 'tablets');

        $this->assertSame(['laptops', CategoryPlacement::UNKNOWN], [$placement->slug(), $placement->reason]);
    }

    public function test_it_does_not_guess_when_two_homes_accept_the_same_type(): void
    {
        Category::query()->where('slug', 'laptops')->update(['product_type_affinity' => 'laptop']);
        Category::query()->create(['name' => 'Gaming laptops', 'slug' => 'gaming-laptops', 'product_type_affinity' => 'laptop',
            'is_active' => true, 'sort_order' => 50]);

        $placement = app(CatalogPlacement::class)->place('laptop', 'computers');

        $this->assertNull($placement->category);
        $this->assertSame(['laptops', 'gaming-laptops'], $placement->candidates);
        $this->assertStringContainsString('laptops, gaming-laptops', (string) $placement->problem());
    }
}
