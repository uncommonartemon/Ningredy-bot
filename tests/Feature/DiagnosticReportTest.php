<?php

namespace Tests\Feature;

use App\Jobs\SendDiagnosticReport;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Support\DiagnosticReport;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Operator, 2026-10-29: the bot is handed to a person; what goes wrong there has to reach the developer as one file. */
class DiagnosticReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_holds_the_searches_story_the_journal_and_what_was_learned_without_secrets(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        config(['services.telegram.bot_token' => '1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'services.openai.key' => 'sk-proj-secretopenaikey0123456789abcdef']);
        $update = TelegramUpdate::query()->create(['update_id' => 900001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Dynabook Tecra A40-K', 'processed_at' => now()]);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'domain' => 'shop.example', 'product_url' => 'https://shop.example/tecra',
            'actor' => 'server', 'phase' => 'image_download', 'action' => 'download_candidates', 'status' => 'completed', 'decision' => 'ready_for_selection',
            'message' => 'token 1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA and key sk-proj-secretopenaikey0123456789abcdef must not leave',
            'input' => ['page_text' => str_repeat('x', 9000)], 'output' => ['downloaded' => 7]]);
        AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-test', 'status' => 'completed',
            'prompt' => 'the prompt stays on the machine', 'activity' => ['stage' => 'photo_applicability'], 'started_at' => now()]);

        $path = app(DiagnosticReport::class)->build(days: 7);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        foreach (['summary.txt', "requests/{$update->id}.txt", 'journal.jsonl', 'ai_runs.jsonl', 'drafts.jsonl', 'learned/rendition_rules.json', 'learned/recipes.json', 'env.redacted.txt'] as $name) {
            $this->assertContains($name, $names);
        }
        $journal = (string) $zip->getFromName('journal.jsonl');
        $this->assertStringContainsString('download_candidates', $journal);
        $this->assertStringContainsString('[redacted]', $journal);
        $this->assertStringNotContainsString('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $journal);
        $this->assertStringNotContainsString('secretopenaikey', $journal);
        $this->assertStringContainsString('…[cut]', $journal);
        $this->assertStringContainsString('Dynabook Tecra A40-K', (string) $zip->getFromName("requests/{$update->id}.txt"));
        $this->assertStringNotContainsString('the prompt stays on the machine', (string) $zip->getFromName('ai_runs.jsonl'));
        $this->assertStringContainsString('photo_applicability', (string) $zip->getFromName('ai_runs.jsonl'));
        $this->assertStringContainsString('"rules"', (string) $zip->getFromName('learned/rendition_rules.json'));
        $this->assertStringNotContainsString('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', (string) $zip->getFromName('env.redacted.txt'));
        $zip->close();
        @unlink($path);
    }

    public function test_the_report_shows_what_the_models_answered_the_photos_the_state_and_the_settings(): void
    {
        // Operator, 2026-11-07: a report that could not explain a failure. WD #409 and Toshiba
        // #420 could be found only from the models' answers, the photos and the category rules.
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Storage::fake('public');
        $update = TelegramUpdate::query()->create(['update_id' => 900010, 'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [],
            'status' => 'completed', 'text' => 'Toshiba MQ04ABF100', 'processed_at' => now()]);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-test', 'status' => 'completed', 'prompt' => 'Fixture',
            'activity' => ['stage' => 'vision'], 'response' => ['images' => [['index' => 1, 'observed_marking' => 'MQ04ABF100']]], 'started_at' => now()]);
        $image = imagecreatetruecolor(1600, 800);
        ob_start();
        imagejpeg($image);
        Storage::disk('public')->put('drafts/1/primary.jpg', (string) ob_get_clean());
        $pending = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1', 'title' => 'Toshiba MQ04ABF100',
            'product_type' => 'component', 'category' => 'components', 'status' => 'pending_review', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        $pending->media()->create(['disk' => 'public', 'path' => 'drafts/1/primary.jpg', 'checksum' => 'x', 'is_primary' => true]);
        AppSetting::put('ai.product_image_vision.model', 'gpt-test');
        AppSetting::put('ai.keys.openai', 'sk-admin-key-that-must-not-travel');
        AppSetting::put('telegram.proxy_url', 'http://user:pass@proxy.example:3128');
        Category::query()->where('slug', 'components')->update(['photo_identity' => 'label']);
        // A shop's address is not a key, even one with SK hynix in it.
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'domain' => 'shop.example',
            'product_url' => 'https://shop.example/genuine-hp-512gb-sk-hynix-m-2-2280-ssd-drive-hfm512gdjtng', 'actor' => 'server',
            'phase' => 'image_download', 'action' => 'download_candidates', 'status' => 'completed', 'decision' => 'ready_for_selection']);

        $path = app(DiagnosticReport::class)->build(days: 7);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString('"observed_marking":"MQ04ABF100"', (string) $zip->getFromName('ai_runs.jsonl'));
        $photo = imagecreatefromstring((string) $zip->getFromName("photos/request-{$update->id}/draft-{$pending->id}-1.jpg"));
        $this->assertSame(800, imagesx($photo));
        $this->assertStringContainsString('Связь с Telegram', (string) $zip->getFromName('summary.txt'));
        $settings = (string) $zip->getFromName('settings.json');
        $this->assertStringContainsString('ai.product_image_vision.model', $settings);
        $this->assertStringContainsString('"photo_identity": "label"', $settings);
        $this->assertStringNotContainsString('ai.keys.openai', $settings);
        $this->assertStringNotContainsString('sk-admin-key', $settings);
        $this->assertStringNotContainsString('proxy.example', $settings);
        $this->assertStringContainsString('sk-hynix-m-2-2280-ssd-drive-hfm512gdjtng', (string) $zip->getFromName('journal.jsonl'));
        $zip->close();
        @unlink($path);
    }

    public function test_the_report_job_sends_the_archive_to_the_chat_as_a_document(): void
    {
        config(['services.telegram.bot_token' => '1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
        array_map('unlink', glob(storage_path('app/reports/ningredy-report-*.zip')) ?: []);

        (new SendDiagnosticReport('98765', 3))->handle(app(DiagnosticReport::class), app(TelegramClient::class));

        // The multipart body is not re-read here: its file handle is closed once the request is out.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument') && $request->hasFile('document'));
        $this->assertSame([], glob(storage_path('app/reports/ningredy-report-*.zip')) ?: []);
    }

    public function test_the_full_report_carries_a_copy_of_the_database_with_its_secrets_blanked(): void
    {
        // Operator, 2026-10-29: "бд просто подойдёт?" - a copy the developer's own tools
        // can open, with keys, tokens and proxy addresses blanked before it leaves. The
        // copy is taken on a file connection of its own: SQLite refuses VACUUM inside
        // the transaction a test runs in, and the live callers never are inside one.
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $file = storage_path('app/reports/live-like.sqlite');
        @unlink($file);
        File::ensureDirectoryExists(dirname($file));
        touch($file);
        config(['database.connections.live_like' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $live = DB::connection('live_like');
        $live->statement('CREATE TABLE app_settings (id INTEGER PRIMARY KEY, key TEXT, value TEXT)');
        $live->statement('CREATE TABLE telegram_updates (id INTEGER PRIMARY KEY, text TEXT)');
        $live->table('app_settings')->insert([
            ['key' => 'ai.keys.openai', 'value' => 'sk-live-key-that-must-not-travel'],
            ['key' => 'telegram.proxy_url', 'value' => 'http://user:pass@proxy.example:3128'],
            ['key' => 'ai.global.model', 'value' => 'gpt-test'],
        ]);
        $live->table('telegram_updates')->insert(['id' => 5, 'text' => 'LG gram 17']);

        $path = app(DiagnosticReport::class)->build(days: 7, withDatabase: true, connection: 'live_like');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $copy = storage_path('app/reports/test-copy.sqlite');
        file_put_contents($copy, (string) $zip->getFromName('database.sqlite'));
        $zip->close();
        $pdo = new \PDO('sqlite:'.$copy);
        $settings = $pdo->query('SELECT key, value FROM app_settings ORDER BY key')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('', $settings['ai.keys.openai']);
        $this->assertSame('', $settings['telegram.proxy_url']);
        $this->assertSame('gpt-test', $settings['ai.global.model']);
        $this->assertSame('LG gram 17', $pdo->query('SELECT text FROM telegram_updates WHERE id = 5')->fetchColumn());
        $pdo = null;
        $live->disconnect();
        @unlink($copy);
        @unlink($path);
        @unlink($file);
        $this->assertSame([], glob(storage_path('app/reports/snapshot-*.sqlite')) ?: []);
    }
}
