<?php

namespace Tests\Feature;

use App\Models\ProductSourceAttempt;
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
