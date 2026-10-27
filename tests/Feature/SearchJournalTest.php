<?php

namespace Tests\Feature;

use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\ProductSearch\SearchJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_sites_that_refused_every_fetch_and_never_gave_images_are_listed(): void
    {
        $this->attempt('walmart.com', 'check_source_access', 'completed', 'http_444', 3);
        // Refused at preflight, but a browser later extracted images: not a refusing site.
        $this->attempt('shop.example', 'check_source_access', 'completed', 'http_403', 2);
        $this->attempt('shop.example', 'extract_page_images', 'completed', 'playwright_images');
        // One refusal is not a pattern.
        $this->attempt('once.example', 'check_source_access', 'completed', 'http_403');
        // An old refusal is outside the window.
        $this->attempt('old.example', 'check_source_access', 'completed', 'http_403', 2, now()->subDays(30));

        $this->assertSame([['host' => 'walmart.com', 'refusals' => 3]], SearchJournal::refusingHosts());
    }

    public function test_a_quick_check_timeout_reads_the_same_in_the_timeline_and_a_pages_last_step(): void
    {
        // Live #234: the timeline said "check_source_access: http_error" and the watcher
        // dropped hp.com while the browser was opening it; it gave eight photos.
        $update = TelegramUpdate::query()->create(['update_id' => 923401, 'chat_id' => '1', 'telegram_user_id' => '1',
            'text' => 'HP EliteBook 840 G11', 'payload' => [], 'status' => 'processing']);
        ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_url' => 'https://www.hp.com/item',
            'domain' => 'www.hp.com', 'actor' => 'server', 'phase' => 'gallery_preflight', 'action' => 'check_source_access',
            'status' => 'completed', 'decision' => 'http_error']);

        $timeline = implode("\n", SearchJournal::timeline($update->id));
        $page = collect(SearchJournal::pagesOpened($update->id, null))->firstWhere('host', 'www.hp.com');

        $this->assertStringContainsString('check_source_access: quick_check_timeout (browser still opens the page)', $timeline);
        $this->assertStringNotContainsString('http_error', $timeline);
        $this->assertSame('check_source_access: quick_check_timeout (browser still opens the page)', $page['last_step']);
    }

    private function attempt(string $host, string $action, string $status, string $decision, int $times = 1, $at = null): void
    {
        foreach (range(1, $times) as $ignored) {
            $row = ProductSourceAttempt::query()->create(['product_url' => "https://{$host}/item", 'domain' => $host,
                'actor' => 'server', 'phase' => 'gallery_preflight', 'action' => $action, 'status' => $status, 'decision' => $decision]);
            if ($at !== null) {
                $row->forceFill(['created_at' => $at])->save();
            }
        }
    }
}
