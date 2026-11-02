<?php

namespace Tests\Feature;

use App\Filament\Pages\CodeDatabasesPage;
use App\Models\User;
use App\Services\BotHealth;
use App\Services\ProductSearch\Codes\CodeDatabases;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Live 2026-11-02: on another machine Icecat had never been connected (no ICECAT_USERNAME
 * in its .env); every lookup said "not configured" and nothing anywhere said so.
 */
class CodeDatabasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.icecat.username' => null]);
    }

    public function test_without_a_login_icecat_is_not_connected_and_the_status_page_says_what_to_do(): void
    {
        $this->assertNull(CodeDatabases::icecatUsername());
        $this->assertSame('not_configured', app(ProductCodeLookup::class)->icecat(null, 'Samsung', 'MZVLQ512HBLU-00B00', null, 'test')['status']);

        $check = collect(app(BotHealth::class)->checks())->firstWhere('key', 'code_databases');
        $this->assertSame('down', $check['state']);
        $this->assertStringContainsString('Icecat не подключён', $check['detail']);
        $this->assertStringContainsString('Настройки → Базы по кодам', $check['hint']);
    }

    public function test_a_login_set_in_the_admin_is_used_before_the_one_in_env(): void
    {
        config(['services.icecat.username' => 'from-env']);
        $this->assertSame('env', CodeDatabases::icecatUsernameSource());

        CodeDatabases::saveIcecatUsername(' from-admin ');
        Http::fake(['https://live.icecat.biz/*' => Http::response(['Message' => 'The requested product is not present in the Icecat database'], 404)]);

        app(ProductCodeLookup::class)->icecat(null, 'Samsung', 'MZVLQ512HBLU-00B00', null, 'test');

        $this->assertSame('admin', CodeDatabases::icecatUsernameSource());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'UserName=from-admin'));
        $check = collect(app(BotHealth::class)->checks())->firstWhere('key', 'code_databases');
        $this->assertSame('up', $check['state']);
        $this->assertStringContainsString('из админки', $check['detail']);

        // An empty field gives the login back to .env.
        CodeDatabases::saveIcecatUsername('');
        $this->assertSame('from-env', CodeDatabases::icecatUsername());
    }

    public function test_the_admin_page_saves_the_login_and_shows_the_component_advice(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));

        Livewire::test(CodeDatabasesPage::class)
            ->assertSee('Не подключён')
            ->assertSee('Процессоры')
            ->assertSee('Intel ARK')
            ->set('data.icecat_username', 'operator-login')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('operator-login', CodeDatabases::icecatUsername());
        Livewire::test(CodeDatabasesPage::class)->assertSee('Подключён (логин из админки)');
    }
}
