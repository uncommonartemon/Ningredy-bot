<?php

namespace Tests\Feature;

use App\Ai\Tools\ManageSearchSettings;
use App\Filament\Pages\ServicesPage;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\BotHealth;
use App\Services\Products\ProductSourcePriority;
use App\Services\ProductSearch\Codes\CodeDatabases;
use App\Services\ProductSearch\Codes\ProductCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;
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
        $this->assertStringContainsString('Настройки → Сервисы', $check['hint']);
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

        Livewire::test(ServicesPage::class)
            ->assertSee('Не подключён')
            ->assertSee('Процессоры')
            ->assertSee('Intel ARK')
            ->set('data.icecat_username', 'operator-login')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('operator-login', CodeDatabases::icecatUsername());
        Livewire::test(ServicesPage::class)->assertSee('Подключён (логин из админки)');
    }

    public function test_a_switched_off_database_is_not_asked_and_its_site_is_left_alone(): void
    {
        config(['services.icecat.username' => 'operator']);
        Http::fake();
        CodeDatabases::setEnabled('icecat', false);
        CodeDatabases::setEnabled('upcitemdb', false);
        $lookup = app(ProductCodeLookup::class);

        $this->assertSame('disabled', $lookup->icecat('8806094215038', null, null, null, 'test')['status']);
        $this->assertSame('disabled', $lookup->icecatById('101333591', null, 'test')['status']);
        $this->assertSame('disabled', $lookup->upcitemdb('8806094215038', null, 'test')['status']);
        Http::assertNothingSent();

        $this->assertContains('icecat.biz', app(ProductSourcePriority::class)->blockedDomains());
        $this->assertTrue(app(ProductSourcePriority::class)->isBlockedUrl('https://images.icecat.biz/img/gallery/1.jpg'));
        $check = collect(app(BotHealth::class)->checks())->firstWhere('key', 'code_databases');
        $this->assertSame('off', $check['state']);
        $this->assertStringContainsString('выключен', $check['detail']);

        CodeDatabases::setEnabled('icecat', true);
        $this->assertFalse(app(ProductSourcePriority::class)->isBlockedUrl('https://images.icecat.biz/img/gallery/1.jpg'));
    }

    public function test_a_login_icecat_does_not_know_is_named_and_not_remembered_as_a_missing_product(): void
    {
        config(['services.icecat.username' => 'typo-login']);
        Http::fake(['https://live.icecat.biz/*' => Http::sequence()
            ->push(['StatusCode' => 3, 'Code' => 404, 'Error' => 'Not Found', 'Message' => 'typo-login the Icecat user is unknown'], 404)
            ->push(['StatusCode' => 8, 'Code' => 404, 'Error' => 'Not Found', 'Message' => 'The requested product is not present in the Icecat database'], 404)]);
        $lookup = app(ProductCodeLookup::class);

        $this->assertSame('bad_login', $lookup->icecat(null, 'Samsung', 'MZ-V9P2T0BW', null, 'test')['status']);
        $check = collect(app(BotHealth::class)->checks())->firstWhere('key', 'code_databases');
        $this->assertSame('down', $check['state']);
        $this->assertStringContainsString('не принял логин', $check['detail']);

        // Not cached: the next look with the same login asks again.
        $this->assertSame('not_found', $lookup->icecat(null, 'Samsung', 'MZ-V9P2T0BW', null, 'test')['status']);
    }

    public function test_the_login_check_tells_a_known_user_from_an_unknown_one_and_from_silence(): void
    {
        Http::fake(['https://live.icecat.biz/*' => Http::sequence()
            ->push(['StatusCode' => 3, 'Message' => 'nobody the Icecat user is unknown'], 404)
            ->push(['StatusCode' => 8, 'Message' => 'The requested product is not present in the Icecat database'], 404)
            ->push(['msg' => 'OK', 'data' => ['GeneralInfo' => ['Title' => 'Samsung 990 PRO']]], 200)
            ->push('', 503)]);
        $lookup = app(ProductCodeLookup::class);

        $this->assertSame('unknown_user', $lookup->checkIcecatLogin('nobody')['status']);
        $this->assertSame('accepted', $lookup->checkIcecatLogin('operator')['status']);
        $this->assertSame('accepted', $lookup->checkIcecatLogin('operator')['status']);
        $this->assertSame('error', $lookup->checkIcecatLogin('operator')['status']);
        // A check is not a lookup: nothing in the journal.
        $this->assertNull(CodeDatabases::lastIcecatAnswer());
    }

    public function test_the_page_switches_a_database_off_and_checks_the_login_in_the_field(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));
        Http::fake(['https://live.icecat.biz/*' => Http::response(['StatusCode' => 3, 'Message' => 'typo the Icecat user is unknown'], 404)]);

        Livewire::test(ServicesPage::class)
            ->assertSet('data.icecat_enabled', true)
            ->set('data.icecat_username', 'typo')
            ->call('checkIcecatLogin')
            ->assertNotified('Логин не принят')
            ->set('data.icecat_enabled', false)
            ->set('data.upcitemdb_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(CodeDatabases::enabled('icecat'));
        $this->assertFalse(CodeDatabases::enabled('upcitemdb'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'UserName=typo'));
        Livewire::test(ServicesPage::class)->assertSet('data.icecat_enabled', false)->assertSee('Выключен');
    }

    public function test_the_chat_can_show_and_switch_a_database_and_a_switched_off_site_is_not_called_banned(): void
    {
        $update = TelegramUpdate::query()->create(['update_id' => 9101, 'telegram_user_id' => '111', 'chat_id' => '222', 'message_id' => 1,
            'text' => 'выключи айскет', 'payload' => [], 'status' => 'processing']);
        $tool = new ManageSearchSettings($update);

        $this->assertTrue(json_decode($tool->handle(new ToolRequest(['action' => 'show'])), true)['settings']['icecat_enabled']);
        $this->assertTrue(json_decode($tool->handle(new ToolRequest(['action' => 'set', 'setting' => 'icecat_enabled', 'value' => 'false'])), true)['ok']);
        $this->assertFalse(CodeDatabases::enabled('icecat'));

        $shown = json_decode($tool->handle(new ToolRequest(['action' => 'show'])), true);
        $this->assertFalse($shown['settings']['icecat_enabled']);
        $this->assertNotContains('icecat.biz', $shown['banned_sites']);
    }
}
