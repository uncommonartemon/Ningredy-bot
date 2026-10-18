<?php

namespace Tests\Feature;

use App\Filament\Pages\DatabaseBrowser as BrowserPage;
use App\Models\AiRun;
use App\Models\ProductSourceAttempt;
use App\Models\User;
use App\Services\Admin\DatabaseBrowser;
use App\Services\Admin\DatabaseValueRedactor;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminDatabaseBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'db-admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'db-admin', 'is_admin' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_only_the_authorized_panel_admin_can_read_database_records(): void
    {
        $this->get('/admin/database-browser')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['name' => 'db-admin', 'is_admin' => false]))
            ->get('/admin/database-browser')->assertForbidden();
        $this->actingAs(User::factory()->create(['name' => 'other-admin', 'is_admin' => true]))
            ->get('/admin/database-browser')->assertForbidden();
        try {
            app(DatabaseBrowser::class)->records('users');
            $this->fail('Direct service access must also check the panel administrator.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_inventory_includes_every_real_table_and_links_existing_resources(): void
    {
        $this->admin();
        $browser = app(DatabaseBrowser::class);
        $this->assertEqualsCanonicalizing(array_filter(Schema::getTableListing(schemaQualified: false), fn ($name) => ! str_starts_with($name, 'sqlite_')), $browser->tables());
        $this->assertArrayHasKey('product_source_page_evidence', $browser->tableOptions());
        $this->assertArrayHasKey('sessions', $browser->tableOptions());
        $this->assertArrayHasKey('products', $browser->resources());
        $this->assertStringContainsString('/admin/products', $browser->resourceUrl('products'));
        $this->get('/admin/database-browser')->assertOk()->assertSee('product_source_page_evidence');
    }

    public function test_invalid_table_and_cross_table_or_forged_record_are_rejected(): void
    {
        $this->admin();
        $browser = app(DatabaseBrowser::class);
        $locator = $browser->records('users')->first()['_locator'];
        foreach ([
            fn () => $browser->records('users; DROP TABLE users'),
            fn () => $browser->record('brands', $locator),
            fn () => $browser->record('users', 'not-an-encrypted-locator'),
            fn () => $browser->record('users', Crypt::encryptString(json_encode(['table' => 'users', 'where' => ['password' => 'x']]))),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Untrusted table/record must be rejected.');
            } catch (HttpException $error) {
                $this->assertSame(404, $error->getStatusCode());
            }
        }
        Livewire::test(BrowserPage::class)->set('databaseTable', 'sqlite_master')->assertStatus(404);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_secrets_never_enter_list_rows_detail_or_livewire_html(): void
    {
        $admin = $this->admin();
        $admin->update(['remember_token' => 'remember-secret-value']);
        DB::table('sessions')->insert(['id' => 'sensitive-session-id', 'user_id' => $admin->id,
            'payload' => base64_encode('serialized-password-secret'), 'last_activity' => time()]);
        DB::table('app_settings')->insert(['key' => 'custom_provider_key', 'value' => 'not-a-standard-api-token']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'serialized-secret-job', 'attempts' => 0,
            'available_at' => time(), 'created_at' => time()]);
        $browser = app(DatabaseBrowser::class);
        foreach (['users', 'sessions', 'app_settings', 'jobs'] as $table) {
            $records = $browser->records($table);
            $serialized = json_encode($records);
            $row = $browser->record($table, $records->first()['_locator']);
            $serialized .= json_encode($row);
            foreach (['remember-secret-value', 'sensitive-session-id', 'serialized-password-secret', 'not-a-standard-api-token', 'serialized-secret-job', $admin->password] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
            $this->assertContains(DatabaseValueRedactor::HIDDEN, $row);
        }
        $page = Livewire::withQueryParams(['table' => 'sessions'])->test(BrowserPage::class);
        $records = $page->instance()->getTableRecords();
        $page->mountAction(TestAction::make('inspect')->table($records->first()))
            ->assertActionMounted(TestAction::make('inspect')->table($records->first()));
        // Filament 5 renders action modals as a Livewire partial, not in html().
        $modal = $page->getMountedActionModalHtml();
        $this->assertTrue(str_contains($modal, DatabaseValueRedactor::HIDDEN), 'The inspection modal must render the server-redacted details.');
        $response = $modal.$page->html().json_encode($page->snapshot);
        $this->assertFalse(str_contains($response, 'sensitive-session-id'));
        $this->assertFalse(str_contains($response, 'serialized-password-secret'));
    }

    public function test_nested_json_and_log_tokens_are_redacted_but_large_evidence_is_complete(): void
    {
        $this->admin();
        config(['services.telegram.bot_token' => 'known-private-token-123']);
        $text = str_repeat('Saved evidence. ', 2000).'END-OF-EVIDENCE';
        $run = AiRun::create(['provider' => 'openai', 'model' => 'fixture', 'status' => 'failed',
            'prompt' => 'Use known-private-token-123', 'started_at' => now(),
            'response' => ['evidence' => $text, 'api_key' => 'secret-nested-value',
                'headers' => ['Authorization' => 'Bearer arbitrary-secret', 'Cookie' => 'session=abc'],
                'setting' => ['key' => 'telegram.bot_token', 'value' => 'wrapped-secret']],
        ]);
        $browser = app(DatabaseBrowser::class);
        $list = $browser->records('ai_runs');
        $this->assertStringNotContainsString('END-OF-EVIDENCE', json_encode($list));
        $row = $browser->record('ai_runs', $list->first()['_locator']);
        $this->assertStringContainsString('END-OF-EVIDENCE', $row['response']);
        foreach (['secret-nested-value', 'arbitrary-secret', 'session=abc', 'wrapped-secret', 'known-private-token-123'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($row));
        }
        $html = $this->get('/admin/ai-runs/'.$run->id)->assertOk()->getContent();
        $this->assertFalse(str_contains($html, 'secret-nested-value'), 'The standard AI infolist must redact nested credentials.');
        $this->assertFalse(str_contains($html, 'known-private-token-123'));
    }

    public function test_native_table_defaults_to_all_and_supports_real_filter_and_pagination(): void
    {
        $this->admin();
        for ($i = 0; $i < 31; $i++) {
            DB::table('product_source_stats')->insert(['domain' => "fixture-$i.example", 'attempt_count' => 0]);
        }
        $page = Livewire::withQueryParams(['table' => 'product_source_stats'])->test(BrowserPage::class);
        $this->assertSame('all', $page->instance()->getTableRecordsPerPage());
        $this->assertCount(31, $page->instance()->getTableRecords());
        $page->filterTable('field', ['column' => 'domain', 'value' => 'fixture-30.example']);
        $this->assertCount(1, $page->instance()->getTableRecords());
        $browser = app(DatabaseBrowser::class);
        $pageTwo = $browser->records('product_source_stats', perPage: 25, page: 2);
        $this->assertSame(31, $pageTwo->total());
        $this->assertCount(6, $pageTwo->items());
        foreach ([fn () => $browser->records('users', sort: 'password', direction: 'asc'),
            fn () => $browser->records('users', filter: ['column' => 'password', 'value' => 'guess'])] as $operation) {
            try {
                $operation();
                $this->fail('Hidden fields must not provide a search/sort oracle.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
    }

    public function test_revoked_access_is_checked_on_next_livewire_request(): void
    {
        $admin = $this->admin();
        $page = Livewire::test(BrowserPage::class);
        $admin->update(['is_admin' => false]);
        $page->set('tableSearch', 'anything')->assertForbidden();
    }

    public function test_server_and_agent_failure_explanations_are_visibly_distinct(): void
    {
        $this->admin();
        $diagnosis = ['version' => 1, 'stage' => 'card_assembly', 'failure_kind' => 'response_format',
            'server_reason' => 'SERVER_FIELD_VALIDATION', 'agent_comment' => 'AGENT_REPAIR_EXPLANATION',
            'blocked_by' => ['source_url'], 'suggested_next_action' => 'Repair this citation.', 'retained' => ['photos' => 2]];
        $attempt = ProductSourceAttempt::create(['domain' => 'example.com', 'product_url' => 'https://example.com/item',
            'actor' => 'server', 'phase' => 'card_assembly', 'action' => 'reconcile_card', 'status' => 'failed',
            'output' => ['failure_diagnosis' => $diagnosis]]);
        $this->get('/admin/product-source-attempts/'.$attempt->id)->assertOk()
            ->assertSee('SERVER_FIELD_VALIDATION')->assertSee('AGENT_REPAIR_EXPLANATION');
        $attempt->update(['output' => []]);
        $this->get('/admin/product-source-attempts/'.$attempt->id)->assertOk()
            ->assertSee('Старая запись')->assertDontSee('AGENT_REPAIR_EXPLANATION');
    }
}
