<?php

namespace Tests\Feature;

use App\Jobs\SendDiagnosticReport;
use App\Models\AiRun;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Support\DiagnosticReport;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/** Operator, 2026-10-29: the bot is handed to a person; what goes wrong there has to reach the developer as one file. */
class DiagnosticReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_holds_the_searches_story_the_journal_and_what_was_learned_without_secrets(): void
    {
        config(['services.telegram.bot_token' => '1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'services.openai.key' => 'sk-secret-openai-key-0123456789']);
        $update = TelegramUpdate::query()->create(['update_id' => 900001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Dynabook Tecra A40-K', 'processed_at' => now()]);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'domain' => 'shop.example', 'product_url' => 'https://shop.example/tecra',
            'actor' => 'server', 'phase' => 'image_download', 'action' => 'download_candidates', 'status' => 'completed', 'decision' => 'ready_for_selection',
            'message' => 'token 1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA and key sk-secret-openai-key-0123456789 must not leave',
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
        $this->assertStringNotContainsString('sk-secret-openai-key', $journal);
        $this->assertStringContainsString('…[cut]', $journal);
        $this->assertStringContainsString('Dynabook Tecra A40-K', (string) $zip->getFromName("requests/{$update->id}.txt"));
        $this->assertStringNotContainsString('the prompt stays on the machine', (string) $zip->getFromName('ai_runs.jsonl'));
        $this->assertStringContainsString('photo_applicability', (string) $zip->getFromName('ai_runs.jsonl'));
        $this->assertStringContainsString('"rules"', (string) $zip->getFromName('learned/rendition_rules.json'));
        $this->assertStringNotContainsString('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', (string) $zip->getFromName('env.redacted.txt'));
        $zip->close();
        @unlink($path);
    }

    public function test_the_report_job_sends_the_archive_to_the_chat_as_a_document(): void
    {
        config(['services.telegram.bot_token' => '1234567890:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

        (new SendDiagnosticReport('98765', 3))->handle(app(DiagnosticReport::class), app(TelegramClient::class));

        // The multipart body is not re-read here: its file handle is closed once the request is out.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument') && $request->hasFile('document'));
        $this->assertSame([], glob(storage_path('app/reports/ningredy-report-*.zip')) ?: []);
    }
}
