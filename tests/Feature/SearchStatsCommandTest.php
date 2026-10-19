<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SearchStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_row_per_request_with_its_outcome_and_button_presses_left_out(): void
    {
        $update = TelegramUpdate::query()->create(['update_id' => 7001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => "Lenovo Legion 5\n16IRX9", 'payload' => [], 'status' => 'completed']);
        TelegramUpdate::query()->create(['update_id' => 7002, 'telegram_user_id' => '1', 'chat_id' => '1',
            'text' => 'draft:add:1', 'payload' => ['callback_query' => ['data' => 'draft:add:1']], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-6-luna',
            'status' => 'completed', 'prompt' => 'x', 'started_at' => now()->subMinutes(6), 'completed_at' => now()]);
        AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'gpt-6-luna',
            'status' => 'failed', 'prompt' => 'y', 'error' => 'cURL error 28: Operation timed out', 'started_at' => now()->subMinutes(3), 'completed_at' => now()->subMinutes(2)]);
        ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '1',
            'status' => 'pending_review', 'title' => 'Lenovo Legion 5', 'product_type' => 'laptop', 'specifications' => [], 'sources' => [],
            'image_urls' => [], 'specifications_reconciled_source_url' => 'https://shop.example/legion', 'gallery_search_stop_reason' => 'exhausted']);
        foreach (['https://shop.example/legion', 'https://other.example/legion'] as $url) {
            ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_url' => $url, 'domain' => parse_url($url, PHP_URL_HOST),
                'actor' => 'server', 'phase' => 'gallery_preflight', 'action' => 'check_source_access', 'status' => 'completed']);
        }
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_url' => 'https://icecat.biz/', 'domain' => 'icecat.biz',
            'actor' => 'code_lookup', 'phase' => 'code_lookup', 'action' => 'lookup_icecat', 'status' => 'completed', 'decision' => 'found']);

        Artisan::call('search:stats', ['--from' => $update->id, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('Lenovo Legion 5 16IRX9', $row['request']);
        $this->assertSame('laptop', $row['type']);
        $this->assertSame(6.0, (float) $row['minutes']);
        $this->assertSame('assembled', $row['card']);
        $this->assertSame(2, $row['sites']);
        $this->assertSame('found', $row['icecat']);
        $this->assertSame(1, $row['ai_timeouts']);
        $this->assertSame(['exhausted' => 1], $report['totals']['stop reasons']);
    }
}
