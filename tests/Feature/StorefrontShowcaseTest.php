<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ManageStorefrontShowcase;
use App\Filament\Pages\StorefrontShowcasePage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Catalog\StorefrontShowcase;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Decision of 2026-11-27: the shop picks the products its start page shows first, in the admin
 * («Настройки → Витрина») and from the chat, through one setting.
 */
class StorefrontShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_page_saves_the_chosen_products_in_their_order(): void
    {
        $this->signIn();
        $first = $this->product('ThinkPad E14 Gen 5', 18990, photo: true);
        $second = $this->product('Latitude 7490', 6990, photo: true);

        Livewire::test(StorefrontShowcasePage::class)
            ->set('data.products', [(string) $second->id, (string) $first->id])
            ->call('save')->assertHasNoErrors();
        $this->assertSame([$second->id, $first->id], StorefrontShowcase::ids());

        // The saved choice is what the page opens with next time.
        Livewire::test(StorefrontShowcasePage::class)->assertSet('data.products', [(string) $second->id, (string) $first->id]);

        Livewire::test(StorefrontShowcasePage::class)->set('data.products', [])->call('save')->assertHasNoErrors();
        $this->assertSame([], StorefrontShowcase::ids());
    }

    public function test_the_admin_page_takes_at_most_twelve_products(): void
    {
        $this->signIn();
        $ids = collect(range(1, 13))->map(fn (int $number): string => (string) $this->product("Laptop {$number}", 1000 + $number)->id)->all();

        Livewire::test(StorefrontShowcasePage::class)->set('data.products', $ids)->call('save')->assertHasErrors(['data.products']);
        $this->assertSame([], StorefrontShowcase::ids());

        Livewire::test(StorefrontShowcasePage::class)->set('data.products', array_slice($ids, 0, 12))->call('save')->assertHasNoErrors();
        $this->assertCount(12, StorefrontShowcase::ids());
    }

    public function test_the_search_finds_products_by_title_brand_model_and_code_and_says_what_the_site_can_show(): void
    {
        $this->signIn();
        $lenovo = Brand::query()->create(['name' => 'Lenovo', 'slug' => 'lenovo']);
        $shown = $this->product('ThinkPad E14 Gen 5', 18990, brand: $lenovo, photo: true, model: '21JK0083CK', sku: 'TP-E14-G5');
        $asleep = $this->product('Latitude 7490', 6990, active: false);
        $this->product('Samsung 990 Pro', 3490, sku: 'MZ-V9P1T0BW');

        $select = Livewire::test(StorefrontShowcasePage::class)->instance()->form
            ->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === 'products');

        $this->assertSame([$shown->id], array_keys($select->getSearchResults('thinkpad')));
        $this->assertSame([$shown->id], array_keys($select->getSearchResults('lenovo')));
        $this->assertSame([$shown->id], array_keys($select->getSearchResults('21JK0083')));
        $this->assertSame([$shown->id], array_keys($select->getSearchResults('tp-e14')));
        $this->assertSame([], $select->getSearchResults('nothing like it'));
        // An option names the product, its brand and price and whether the site can show it.
        $this->assertSame("#{$shown->id} ThinkPad E14 Gen 5 · Lenovo · 18 990 CZK · в продаже · с фото", $select->getSearchResults('thinkpad')[$shown->id]);
        $this->assertSame("#{$asleep->id} Latitude 7490 · 6 990 CZK · снят с продажи · без фото", $select->getSearchResults('latitude')[$asleep->id]);
    }

    public function test_the_chat_shows_and_changes_the_showcase_through_the_same_setting(): void
    {
        $products = collect(range(1, 4))->map(fn (int $number): Product => $this->product("Laptop {$number}", 1000 * $number, photo: $number !== 3));
        $tool = new ManageStorefrontShowcase(TelegramUpdate::query()->create(['update_id' => 99001, 'telegram_user_id' => '1',
            'chat_id' => '1', 'text' => 'showcase', 'payload' => [], 'status' => 'processing']));
        $call = fn (array $arguments): array => json_decode((string) $tool->handle(new ToolRequest($arguments)), true);

        $this->assertSame([], $call(['action' => 'show'])['products']);
        $set = $call(['action' => 'set', 'product_ids' => [$products[1]->id, $products[0]->id]]);
        $this->assertTrue($set['ok']);
        $this->assertSame([$products[1]->id, $products[0]->id], StorefrontShowcase::ids());

        $added = $call(['action' => 'add', 'product_ids' => [$products[2]->id], 'position' => 1]);
        $this->assertSame([$products[2]->id, $products[1]->id, $products[0]->id], array_column($added['products'], 'product_id'));
        // A chosen product without a photo is said to be skipped on the site.
        $this->assertFalse($added['products'][0]['shown_on_site']);
        $this->assertTrue($added['products'][1]['shown_on_site']);

        $call(['action' => 'add', 'product_ids' => [$products[3]->id]]);
        $call(['action' => 'remove', 'product_ids' => [$products[1]->id]]);
        $this->assertSame([$products[2]->id, $products[0]->id, $products[3]->id], StorefrontShowcase::ids());

        $missing = $call(['action' => 'add', 'product_ids' => [999999]]);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('999999', $missing['error']);
        $many = $call(['action' => 'set', 'product_ids' => range(1, 13)]);
        $this->assertFalse($many['ok']);
        $this->assertSame([$products[2]->id, $products[0]->id, $products[3]->id], StorefrontShowcase::ids());
    }

    public function test_the_assistant_has_the_showcase_tool_and_knows_when_to_use_it(): void
    {
        $agent = new ServerAssistantAgent(new TelegramUpdate);

        $this->assertContains(ManageStorefrontShowcase::class, collect($agent->tools())->map(fn (object $tool): string => $tool::class)->all());
        $this->assertStringContainsString('ManageStorefrontShowcase', (string) $agent->instructions());
    }

    private function signIn(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
    }

    private function product(string $title, int $price, bool $active = true, bool $photo = false, ?Brand $brand = null,
        ?string $model = null, ?string $sku = null): Product
    {
        $product = Product::query()->create(['category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'brand_id' => $brand?->id, 'title' => $title, 'slug' => str($title)->slug()->toString(), 'model' => $model,
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => $active, 'published_at' => now()]);
        $product->variants()->create(['name' => 'Base', 'price' => $price, 'currency' => 'CZK', 'sku' => $sku,
            'stock_status' => 'in_stock', 'is_default' => true, 'is_active' => true]);
        if ($photo) {
            $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => "products/{$product->slug}/1.webp",
                'verification_status' => 'verified']);
        }

        return $product;
    }
}
