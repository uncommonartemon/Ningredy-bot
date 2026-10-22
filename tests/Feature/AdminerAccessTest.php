<?php

namespace Tests\Feature;

use App\Filament\Pages\DatabaseBrowser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Only the refusals are requested here: an admitted request hands over to
// Adminer, which ends the PHP process. The editor itself was checked in a
// browser against a copy of the database.
class AdminerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'db-admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_the_editor_sends_a_stranger_to_the_login_and_refuses_another_account(): void
    {
        $this->get('/admin/adminer')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

        $this->actingAs(User::factory()->create(['name' => 'someone', 'is_admin' => false]))
            ->get('/admin/adminer')->assertForbidden();
    }

    public function test_the_editor_does_not_exist_through_the_tunnel_even_for_the_admin(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'db-admin', 'is_admin' => true]))
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->get('/admin/adminer')->assertNotFound();
    }

    public function test_the_database_page_links_to_the_editor_only_at_this_computer(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'db-admin', 'is_admin' => true]));

        Livewire::test(DatabaseBrowser::class)->assertActionVisible('tables')
            ->assertActionHasUrl('tables', route('admin.adminer'));
        Livewire::withHeaders(['X-Forwarded-For' => '203.0.113.7'])->test(DatabaseBrowser::class)->assertActionHidden('tables');
    }
}
