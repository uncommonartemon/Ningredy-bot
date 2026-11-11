<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerGroups\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: groups of customers who see prices of their own. A customer signs up
 * through the group's invitation link; a product without a price for the group shows its
 * ordinary price; everyone else sees ordinary prices everywhere.
 */
class CustomerGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_of_a_group_sees_its_price_and_everyone_else_the_ordinary_one(): void
    {
        [$group, $customer] = $this->groupWithCustomer();
        $priced = $this->product('Priced for the group', 1000);
        $ordinary = $this->product('No price for the group', 500);
        CustomerGroupPrice::query()->create(['customer_group_id' => $group->id, 'product_variant_id' => $priced->id, 'price' => 800]);
        $cards = fn () => collect($this->get('/catalog')->assertOk()->viewData('page')['props']['products']['data'])->keyBy('title');

        $guest = $cards();
        $this->assertSame(1000.0, (float) $guest['Priced for the group']['price']);
        $this->assertFalse($guest['Priced for the group']['own_price']);
        $this->get('/catalog')->assertInertia(fn (Assert $page) => $page->where('customer', null)->etc());

        $this->actingAs($customer, 'customer');
        // Each real request starts with a fresh container; the tests share one.
        $this->app->forgetScopedInstances();
        $own = $cards();
        $this->assertSame(800.0, (float) $own['Priced for the group']['price']);
        $this->assertSame(1000.0, (float) $own['Priced for the group']['compare_at_price']);
        $this->assertTrue($own['Priced for the group']['own_price']);
        $this->assertSame(500.0, (float) $own['No price for the group']['price']);
        $this->assertFalse($own['No price for the group']['own_price']);
        $this->get(route('products.show', $priced->product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('product.variants.0.price', 800)->where('product.variants.0.compare_at_price', 1000)
            ->where('product.variants.0.own_price', true)
            ->where('customer.name', 'Partner')->where('customer.group', 'Wholesale')->etc());

        // A group switched off, or an account closed, is back to ordinary prices at once.
        $group->update(['is_active' => false]);
        $this->actingAs($customer->fresh(), 'customer')->app->forgetScopedInstances();
        $this->assertSame(1000.0, (float) $cards()['Priced for the group']['price']);
        $group->update(['is_active' => true]);
        $customer->update(['is_active' => false]);
        $this->actingAs($customer->fresh(), 'customer')->app->forgetScopedInstances();
        $this->assertSame(1000.0, (float) $cards()['Priced for the group']['price']);
    }

    public function test_a_customer_signs_up_only_through_a_groups_open_invitation_link(): void
    {
        $group = CustomerGroup::query()->create(['name' => 'Wholesale']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $group->invite_code);
        $link = '/account/join/'.$group->invite_code;

        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Account/Join')
            ->where('invitation.group', 'Wholesale')->where('invitation.code', $group->invite_code));
        $this->post($link, ['name' => ' New Partner ', 'email' => 'Partner@Example.test', 'company' => 'Partner s.r.o.',
            'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1'])->assertRedirect('/account');

        $customer = Customer::query()->sole();
        $this->assertSame([$group->id, 'New Partner', 'partner@example.test', 'Partner s.r.o.'],
            [$customer->customer_group_id, $customer->name, $customer->email, $customer->company]);
        $this->assertTrue(Hash::check('long-enough-1', $customer->password));
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->get('/account')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Account/Index')
            ->where('account.group', 'Wholesale')->where('account.email', 'partner@example.test'));
        // A customer's session is not the admin's.
        $this->assertGuest('web');

        $this->post('/account/logout')->assertRedirect('/catalog');
        $this->assertGuest('customer');
        // The same address cannot sign up twice, and the form says so in its own words.
        $this->post($link, ['name' => 'Again', 'email' => 'partner@example.test',
            'password' => 'long-enough-2', 'password_confirmation' => 'long-enough-2'])->assertSessionHasErrors(['email' => 'taken']);
        $this->assertSame(1, Customer::query()->count());

        // A closed link, a group out of use and an unknown code lead nowhere.
        $group->update(['registration_open' => false]);
        $this->get($link)->assertNotFound();
        $group->update(['registration_open' => true, 'is_active' => false]);
        $this->post($link, ['name' => 'X', 'email' => 'x@example.test', 'password' => 'long-enough-3', 'password_confirmation' => 'long-enough-3'])->assertNotFound();
        $this->get('/account/join/'.str_repeat('a', 24))->assertNotFound();
        $this->get('/account/join')->assertNotFound();
    }

    public function test_sign_in_answers_one_way_for_every_refusal_and_stops_after_too_many_attempts(): void
    {
        [, $customer] = $this->groupWithCustomer();
        $this->get('/account')->assertRedirect('/account/login');
        $this->get('/account/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Account/Login'));

        $this->post('/account/login', ['email' => 'partner@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors(['email' => 'credentials']);
        $this->post('/account/login', ['email' => 'nobody@example.test', 'password' => 'partner-password'])->assertSessionHasErrors(['email' => 'credentials']);
        $customer->update(['is_active' => false]);
        $this->post('/account/login', ['email' => 'partner@example.test', 'password' => 'partner-password'])->assertSessionHasErrors(['email' => 'credentials']);
        $this->assertGuest('customer');

        $customer->update(['is_active' => true]);
        $this->post('/account/login', ['email' => ' Partner@Example.test ', 'password' => 'partner-password'])->assertRedirect('/catalog');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotNull($customer->fresh()->last_login_at);
        $this->post('/account/logout');

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->post('/account/login', ['email' => 'partner@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors(['email' => 'credentials']);
        }
        $this->post('/account/login', ['email' => 'partner@example.test', 'password' => 'partner-password'])->assertSessionHasErrors(['email' => 'throttled']);
        $this->assertGuest('customer');
    }

    public function test_the_product_form_has_a_price_per_group_and_an_empty_one_is_the_ordinary_price(): void
    {
        $this->admin();
        $wholesale = CustomerGroup::query()->create(['name' => 'Wholesale']);
        $friends = CustomerGroup::query()->create(['name' => 'Friends']);
        $variant = $this->product('In the form', 1000);
        CustomerGroupPrice::query()->create(['customer_group_id' => $friends->id, 'product_variant_id' => $variant->id, 'price' => 950]);

        $page = Livewire::test(EditProduct::class, ['record' => $variant->product_id]);
        $key = array_key_first($page->get('data.variants'));
        $this->assertEquals(950, $page->get("data.variants.$key.group_prices.{$friends->id}"));
        $this->assertNull($page->get("data.variants.$key.group_prices.{$wholesale->id}"));

        $page->set("data.variants.$key.group_prices.{$wholesale->id}", '800.50')
            ->set("data.variants.$key.group_prices.{$friends->id}", '')
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame([$wholesale->id => '800.50'], CustomerGroupPrice::query()->where('product_variant_id', $variant->id)
            ->pluck('price', 'customer_group_id')->all());
        $this->assertSame('1000.00', $variant->fresh()->price);
    }

    public function test_the_admin_makes_a_group_replaces_its_link_and_sets_a_customers_password(): void
    {
        $this->admin();
        Livewire::test(CreateCustomerGroup::class)->fillForm(['name' => 'Wholesale'])->call('create')->assertHasNoFormErrors();
        $group = CustomerGroup::query()->sole();
        Livewire::test(ListCustomerGroups::class)->assertCanSeeTableRecords([$group]);

        $before = $group->invite_code;
        $edit = Livewire::test(EditCustomerGroup::class, ['record' => $group->id]);
        $this->assertSame($group->inviteUrl(), $edit->get('data.invite_url'));
        $edit->callAction('newInvitation');
        $this->assertNotSame($before, $group->fresh()->invite_code);
        $this->get('/account/join/'.$before)->assertNotFound();

        $customer = Customer::query()->create(['customer_group_id' => $group->id, 'name' => 'Partner', 'email' => 'partner@example.test', 'password' => 'first-password']);
        Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$customer]);
        // An empty password field changes nothing; a filled one replaces the password.
        Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['company' => 'Partner s.r.o.'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('first-password', $customer->fresh()->password));
        Livewire::test(EditCustomer::class, ['record' => $customer->id])->fillForm(['password' => 'second-password'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('second-password', $customer->fresh()->password));
    }

    public function test_the_chat_reads_customers_without_their_passwords_and_sets_a_groups_price(): void
    {
        [$group] = $this->groupWithCustomer();
        $variant = $this->product('Priced from the chat', 1000);
        $operator = app(DatabaseOperatorService::class);

        $rows = $operator->query('customers')['rows'];
        $this->assertSame('partner@example.test', $rows[0]['email']);
        $this->assertArrayNotHasKey('password', $rows[0]);
        $this->assertArrayNotHasKey('remember_token', $rows[0]);
        $tables = collect($operator->describe()['tables'] ?? $operator->describe())->flatten()->implode(' ');
        $this->assertStringNotContainsString('remember_token', $tables);

        $plan = $operator->prepare([['action' => 'create', 'table' => 'customer_group_prices',
            'values' => ['customer_group_id' => $group->id, 'product_variant_id' => $variant->id, 'price' => 870]]]);
        $operator->execute($plan);
        $this->assertSame('870.00', CustomerGroupPrice::query()->sole()->price);
    }

    /** @return array{0: CustomerGroup, 1: Customer} */
    private function groupWithCustomer(): array
    {
        $group = CustomerGroup::query()->create(['name' => 'Wholesale']);
        $customer = Customer::query()->create(['customer_group_id' => $group->id, 'name' => 'Partner',
            'email' => 'partner@example.test', 'password' => 'partner-password']);

        return [$group, $customer];
    }

    private function product(string $title, int $price): ProductVariant
    {
        $product = Product::query()->create(['title' => $title, 'category_id' => Category::query()->where('slug', 'laptops')->value('id'),
            'product_type' => 'laptop', 'status' => 'published', 'is_active' => true, 'published_at' => now()]);

        return $product->variants()->create(['condition' => 'new', 'price' => $price, 'currency' => 'CZK', 'quantity' => 1,
            'stock_status' => 'in_stock', 'is_default' => true, 'is_active' => true]);
    }

    private function admin(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }
}
