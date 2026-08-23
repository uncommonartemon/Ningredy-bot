<?php

namespace Tests\Feature;

use App\Ai\Tools\GetSourceAttemptHistory;
use App\Models\ProductSourceAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetSourceAttemptHistoryToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_this_url_scope_returns_only_attempts_for_the_exact_url(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
            'output' => [
                'failure_kind' => 'too_small',
                'rejected_candidates' => [
                    ['url' => 'https://example.com/a/1.jpg', 'reason' => 'too_small_400x300'],
                ],
            ],
        ]);
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/b',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
        ]);

        $result = json_decode(
            (string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $result['attempts']);
        $this->assertSame('too_small', $result['attempts'][0]['failure_kind']);
        $this->assertSame('too_small_400x300', $result['attempts'][0]['rejected_candidates'][0]['reason']);
    }

    public function test_this_domain_scope_includes_every_url_tried_for_the_domain(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'gallery_training',
            'action' => 'train_recipe',
            'status' => 'failed',
        ]);
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/b',
            'actor' => 'playwright',
            'phase' => 'gallery_training',
            'action' => 'train_recipe',
            'status' => 'failed',
        ]);

        $result = json_decode(
            (string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['scope' => 'this_domain'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertCount(2, $result['attempts']);
    }

    public function test_phase_filter_narrows_the_results(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'ai',
            'phase' => 'gallery_preflight',
            'action' => 'assess_page_suitability',
            'status' => 'completed',
        ]);
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
        ]);

        $result = json_decode(
            (string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['scope' => 'this_url', 'phase' => 'image_download'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $result['attempts']);
        $this->assertSame('image_download', $result['attempts'][0]['phase']);
    }

    public function test_never_exposes_the_raw_output_blob(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
            'output' => ['huge_dom_dump' => str_repeat('x', 5000)],
        ]);

        $result = json_decode(
            (string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayNotHasKey('output', $result['attempts'][0]);
        $this->assertArrayNotHasKey('huge_dom_dump', $result['attempts'][0]);
    }
}
