<?php

namespace Tests\Feature;

use App\Models\ImageRenditionRule;
use App\Models\ProductSourceAttempt;
use App\Models\ReferenceSite;
use App\Models\User;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\ProductSearch\Catalogs\ReferenceSites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Operator, 2026-11-02: «добавить таблицу с запросами + ссылки… использовать при поиске
 * ссд и хард диск»; a colleague had asked the bot to take SSD speeds from
 * harddrivebenchmark.net for good and photos from other sites.
 */
class ReferenceSitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_starting_rows_arrive_with_the_table_and_the_agent_hears_only_of_sites_the_bot_opens(): void
    {
        $this->assertTrue(ReferenceSite::query()->where('domain', 'harddrivebenchmark.net')->where('use_for_photos', false)->exists());
        $this->assertSame(3, ReferenceSite::query()->where('domain', 'techpowerup.com')->where('access', ReferenceSite::ACCESS_PERSON)->count());

        $hints = collect(app(ReferenceSites::class)->hints(['MZALQ512HALU-000L1']));

        $benchmark = $hints->firstWhere('site', 'harddrivebenchmark.net');
        $this->assertSame(['site:harddrivebenchmark.net MZALQ512HALU-000L1'], $benchmark['search']);
        $this->assertSame('facts only, never photos', $benchmark['take']);
        $this->assertNull($hints->firstWhere('site', 'techpowerup.com'));
        // A search that needs the model, before the model is known, keeps the placeholder for the agent.
        $this->assertSame(['site:intel.com {model} specifications'], $hints->firstWhere('site', 'intel.com')['search']);
    }

    public function test_a_site_only_a_person_opens_gives_the_operator_a_link_for_this_product(): void
    {
        $links = collect(app(ReferenceSites::class)->personLinks(['MZALQ512HALU-000L1'], 'PM991 512GB'));

        $this->assertContains('https://www.techpowerup.com/ssd-specs/?q=PM991%20512GB', $links->pluck('link')->all());
    }

    public function test_what_a_row_forbids_is_kept_by_the_server(): void
    {
        $sites = app(ReferenceSites::class);
        $sources = $sites->restrictRoles([
            ['url' => 'https://www.harddrivebenchmark.net/hdd.php?hdd=SAMSUNG+MZALQ512HALU-000L1', 'roles' => ['facts', 'exact_photo'],
                'image_urls' => ['https://www.harddrivebenchmark.net/x.png']],
            ['url' => 'https://www.techpowerup.com/ssd-specs/samsung-pm991-512-gb.d200', 'roles' => ['facts'], 'image_urls' => []],
            ['url' => 'https://semiconductor.samsung.com/ssd/pc-ssd/pm991/', 'roles' => ['facts', 'exact_photo'], 'image_urls' => []],
            ['url' => 'https://www.samsung.com/cz/memory-storage/x/', 'roles' => ['exact_photo'], 'image_urls' => []],
            // A photo-only candidate on a facts-only site has nothing left to give (audit 2026-11-04, C7).
            ['url' => 'https://www.harddrivebenchmark.net/hdd.php?hdd=sibling', 'roles' => ['compatible_photo_candidate'], 'image_urls' => []],
        ]);

        $this->assertCount(3, $sources);
        $this->assertSame(['facts'], $sources[0]['roles']);
        $this->assertSame([], $sources[0]['image_urls']);
        $this->assertSame(['facts', 'exact_photo'], $sources[1]['roles']);
        // A row for semiconductor.samsung.com is not a row for the whole of samsung.com.
        $this->assertSame(['exact_photo'], $sources[2]['roles']);
        $this->assertFalse($sites->allowsPhotos('https://www.harddrivebenchmark.net/x.png'));
        $this->assertTrue($sites->allowsPhotos('https://shop.example/x.png'));
    }

    public function test_the_agents_word_on_each_site_counts_and_a_site_that_never_finds_anything_switches_off(): void
    {
        $sites = app(ReferenceSites::class);
        $sites->record([['site' => 'www.harddrivebenchmark.net', 'result' => 'found'], ['site' => 'notebookcheck.net', 'result' => 'not_applicable']], null);

        $this->assertSame(1, ReferenceSite::query()->where('domain', 'harddrivebenchmark.net')->firstOrFail()->hits);
        $this->assertSame(0, ReferenceSite::query()->where('domain', 'notebookcheck.net')->firstOrFail()->misses);
        $this->assertTrue(ProductSourceAttempt::query()->where('phase', 'reference_site')->where('decision', 'found')->exists());

        for ($miss = 0; $miss < 15; $miss++) {
            $sites->record([['site' => 'notebookcheck.net', 'result' => 'not_found']], null);
        }
        $notebookcheck = ReferenceSite::query()->where('domain', 'notebookcheck.net')->firstOrFail();
        $this->assertFalse($notebookcheck->enabled);
        $this->assertStringStartsWith('Отключён автоматически', $notebookcheck->last_result);
    }

    public function test_the_admin_lists_the_sites_and_opens_the_form(): void
    {
        config(['services.admin.name' => 'ningredy']);
        $this->actingAs(User::factory()->create(['name' => 'ningredy', 'is_admin' => true]));

        $this->get('/admin/reference-sites')->assertOk()->assertSee('harddrivebenchmark.net')->assertSee('только человек');
        $this->get('/admin/reference-sites/create')->assertOk()->assertSee('Кто открывает сайт');
    }

    public function test_the_chat_adds_a_site_and_switches_a_photo_rule_off_but_never_writes_a_rule(): void
    {
        $service = app(DatabaseOperatorService::class);
        $service->execute($service->prepare([['action' => 'create', 'table' => 'reference_sites', 'values' => [
            'domain' => 'https://www.SSD-Tester.com/', 'applies_to' => 'SSD', 'use_for_photos' => false]]]));
        $this->assertTrue(ReferenceSite::query()->where('domain', 'ssd-tester.com')->where('access', ReferenceSite::ACCESS_BOT)->exists());

        $rule = ImageRenditionRule::query()->firstOrFail();
        $service->execute($service->prepare([['action' => 'update', 'table' => 'image_rendition_rules',
            'selection' => ['ids' => [$rule->id]], 'values' => ['status' => 'disabled']]]));
        $this->assertSame('disabled', $rule->fresh()->status);

        try {
            $service->prepare([['action' => 'create', 'table' => 'image_rendition_rules', 'values' => ['status' => 'active']]]);
            $this->fail('A rendition rule was created from the chat.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Правила фото создаёт и проверяет агент', implode(' ', $exception->errors()['action']));
        }
    }
}
