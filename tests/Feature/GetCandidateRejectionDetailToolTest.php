<?php

namespace Tests\Feature;

use App\Ai\Tools\GetCandidateRejectionDetail;
use App\Models\ProductSourceAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetCandidateRejectionDetailToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_the_recorded_rejection_reason_for_an_exact_url_match(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
            'output' => [
                'rejected_candidates' => [
                    ['url' => 'https://example.com/a/1.jpg', 'reason' => 'too_small_400x300'],
                ],
            ],
        ]);

        $result = json_decode(
            (string) (new GetCandidateRejectionDetail('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['image_url' => 'https://example.com/a/1.jpg'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($result['found']);
        $this->assertSame('too_small_400x300', $result['reason']);
    }

    public function test_matches_a_different_cdn_rendition_of_the_same_photo(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'example.com',
            'product_url' => 'https://example.com/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
            'output' => [
                'rejected_candidates' => [
                    ['url' => 'https://example.com/images500x500/a-1.jpg', 'reason' => 'unreachable'],
                ],
            ],
        ]);

        // Same physical photo, different CDN size-bucket segment - the tool
        // must match via imageAssetKey(), not a literal string comparison.
        $result = json_decode(
            (string) (new GetCandidateRejectionDetail('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['image_url' => 'https://example.com/images800x800/a-1.jpg'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($result['found']);
        $this->assertSame('unreachable', $result['reason']);
    }

    public function test_reports_not_found_instead_of_inventing_a_reason(): void
    {
        $result = json_decode(
            (string) (new GetCandidateRejectionDetail('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['image_url' => 'https://example.com/never-seen.jpg'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertFalse($result['found']);
    }

    public function test_only_looks_at_this_domains_own_attempts(): void
    {
        ProductSourceAttempt::query()->create([
            'domain' => 'other.example',
            'product_url' => 'https://other.example/products/a',
            'actor' => 'playwright',
            'phase' => 'image_download',
            'action' => 'download_candidates',
            'status' => 'failed',
            'output' => [
                'rejected_candidates' => [
                    ['url' => 'https://other.example/a/1.jpg', 'reason' => 'too_small_400x300'],
                ],
            ],
        ]);

        $result = json_decode(
            (string) (new GetCandidateRejectionDetail('https://example.com/products/a', 'example.com'))
                ->handle(new ToolRequest(['image_url' => 'https://other.example/a/1.jpg'])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertFalse($result['found']);
    }
}
