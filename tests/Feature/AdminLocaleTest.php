<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Http\Middleware\SetAdminLocale;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_and_ukrainian_admin_pages_render_and_locale_is_restored(): void
    {
        $initial = app()->getLocale();
        foreach (['en' => 'Title', 'uk' => 'Назва'] as $locale => $label) {
            $this->post('/admin/locale/'.$locale)->assertRedirect('/admin')->assertSessionHas('admin_locale', $locale);
            $this->get('/admin/products/create')->assertOk()->assertSee($label);
            $this->assertSame($initial, app()->getLocale());
        }
    }

    public function test_admin_dictionaries_cover_the_same_custom_interface_labels(): void
    {
        $reference = json_decode(file_get_contents(lang_path('cs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['en', 'uk'] as $locale) {
            $dictionary = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([], array_values(array_diff(array_keys($reference), array_keys($dictionary))));
            $this->assertNotSame('Распакованный', $dictionary['Распакованный']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
    }

    public function test_admin_can_switch_interface_without_changing_global_locale(): void
    {
        $initialLocale = app()->getLocale();
        $this->post('/admin/locale/cs')->assertRedirect('/admin')->assertSessionHas('admin_locale', 'cs');
        $this->get('/admin/products/create')->assertOk()->assertSee('Parametry a konfigurace')->assertSee('Název')
            ->assertSee('Zveřejnění')->assertDontSee('Ключ дедупликации');
        $this->assertSame($initialLocale, app()->getLocale());
        $this->get('/admin/categories/create')->assertOk()->assertSee('Výchozí parametry');
        $this->get('/admin/ai-settings-page')->assertOk()->assertSee('Uložit nastavení AI');
        $this->post('/admin/locale/ru')->assertSessionHas('admin_locale', 'ru');
        $this->get('/admin/products/create')->assertOk()->assertSee('Характеристики и конфигурации');
    }

    public function test_locale_is_allowlisted_and_admin_only(): void
    {
        $this->post('/admin/locale/invalid')->assertNotFound();
        $this->actingAs(User::factory()->create(['name' => 'outsider', 'is_admin' => false]));
        $this->post('/admin/locale/cs')->assertForbidden();
    }

    public function test_expired_session_cannot_change_locale(): void
    {
        auth()->logout();
        $this->post('/admin/locale/cs')->assertForbidden()->assertSessionMissing('admin_locale');
    }

    public function test_czech_form_validation_and_persistent_livewire_middleware(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->assertContains(SetAdminLocale::class, Livewire::getPersistentMiddleware());
        app()->setLocale('cs');
        Livewire::test(CreateProduct::class)->call('create')->assertHasFormErrors(['title' => 'required'])
            ->assertSee('je povinné');
    }
}
