<?php

namespace Tests\Feature;

use App\Ai\Tools\GetSourceAttemptHistory;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetSourceAttemptHistoryToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposes_recorded_card_blockers_without_copying_the_previous_card(): void
    {
        $attempt = $this->attempt(['phase' => 'card_assembly', 'output' => [
            'made_progress' => false,
            'repair_context' => ['previous_candidate' => ['secret' => 'whole-card'],
                'failure_kind' => 'specification_evidence', 'validation_errors' => ['ram' => ['Missing exact configuration evidence']],
                'unresolved_fields' => [['key' => 'ram', 'status' => 'unknown', 'reason' => 'Source describes another configuration']],
                'recovery_action' => 'retry_current_source', 'photo_result_preserved' => true],
            'recovery' => ['stage' => 'card_assembly', 'message' => 'RAM is not established',
                'available_actions' => ['SearchProductTextEvidence'], 'retained' => ['raw_html' => 'private-blob']],
        ]]);
        $result = $this->history(['phase' => 'card_assembly']);
        $row = $result['attempts'][0];
        $this->assertSame($attempt->id, $row['id']);
        $this->assertSame($attempt->product_url, $row['product_url']);
        $this->assertSame('ram', $row['repair_context']['unresolved_fields'][0]['key']);
        $this->assertSame(['SearchProductTextEvidence'], $row['recovery']['available_actions']);
        $this->assertFalse($row['made_progress']);
        $this->assertFalse($row['details_truncated']);
        $this->assertStringNotContainsString('whole-card', json_encode($result));
        $this->assertStringNotContainsString('private-blob', json_encode($result));
    }

    public function test_returns_unknown_and_conflicting_physical_evidence_from_nested_assessments(): void
    {
        foreach (['physical_checks', '_photo_applicability', 'photo_applicability'] as $key) {
            $checks = ['revision' => ['status' => 'unknown', 'evidence' => 'No revision shown'],
                'color' => ['status' => 'conflicting', 'evidence' => 'Silver instead of black'],
                'platform' => ['status' => 'match', 'evidence' => 'Same model']];
            $this->attempt(['phase' => 'source_selection', 'decision' => 'uncertain',
                'output' => [$key => $key === 'physical_checks' ? $checks : ['physical_checks' => $checks]]]);
        }
        foreach ($this->history(['phase' => 'source_selection'])['attempts'] as $row) {
            $this->assertSame('uncertain', $row['decision']);
            $this->assertSame('No revision shown', $row['physical_checks']['revision']['evidence']);
            $this->assertSame('conflicting', $row['physical_checks']['color']['status']);
            $this->assertArrayNotHasKey('platform', $row['physical_checks']);
        }
    }

    public function test_keeps_download_and_accepted_counts_distinct_and_unknown_is_not_zero(): void
    {
        $this->attempt(['phase' => 'image_verification', 'input' => ['downloaded_images' => 7], 'output' => ['candidate_urls' => 9, 'accepted_images' => 2]]);
        $this->attempt(['phase' => 'image_verification', 'input' => ['downloaded_images' => 3], 'output' => ['accepted_images' => null]]);
        $rows = $this->history(['phase' => 'image_verification'])['attempts'];
        $this->assertSame(['accepted_images' => null, 'downloaded_images' => 3], $rows[0]['counts']);
        $this->assertSame(['candidate_urls' => 9, 'accepted_images' => 2, 'downloaded_images' => 7], $rows[1]['counts']);
    }

    public function test_large_unicode_history_is_bounded_without_losing_the_newest_failure_reason(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $latest = $this->attempt(['message' => 'Failure '.$i.': missing revision proof. '.str_repeat('Ошибка\\"', 1000),
                'product_url' => 'https://example.com/products/'.str_repeat('long-product-', 40).$i,
                'output' => ['error' => str_repeat('Ошибка\\"', 1000), 'accepted_images' => 2, 'made_progress' => false,
                    'repair_context' => ['validation_errors' => array_fill(0, 30, str_repeat('証拠\\"', 1000))],
                    'recovery' => ['message' => str_repeat('Причина', 1000), 'available_actions' => array_fill(0, 30, str_repeat('action', 100))],
                    'rejected_candidates' => array_fill(0, 30, ['url' => str_repeat('url', 1000), 'reason' => str_repeat('reason', 1000)])]]);
        }
        $json = (string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
            ->handle(new ToolRequest(['limit' => 20, 'scope' => 'this_domain']));
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(32768, strlen($json));
        $this->assertTrue($result['details_truncated']);
        $this->assertGreaterThan(0, $result['omitted_attempts']);
        $this->assertSame($latest->id, $result['attempts'][0]['id']);
        $this->assertSame($latest->product_url, $result['attempts'][0]['product_url']);
        $this->assertSame(2, $result['attempts'][0]['counts']['accepted_images']);
        $this->assertFalse($result['attempts'][0]['made_progress']);
        $this->assertStringStartsWith('Failure 19: missing revision proof.', $result['attempts'][0]['message']);
        foreach ($result['attempts'] as $row) {
            $this->assertLessThanOrEqual(4096, strlen(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        }
    }

    public function test_history_remains_scoped_to_the_current_update_or_recipe_version(): void
    {
        $updates = collect([1, 2])->map(fn ($id) => TelegramUpdate::create(['update_id' => $id, 'payload' => []]));
        $versions = collect([1, 2])->map(fn () => ProductGalleryRecipeVersion::create([
            'domain' => 'example.com', 'product_url' => 'https://example.com/products/a',
            'trigger' => 'test', 'provider' => 'openai', 'model' => 'test',
        ]));
        $first = $this->attempt(['telegram_update_id' => $updates[0]->id, 'product_gallery_recipe_version_id' => $versions[0]->id]);
        $this->attempt(['telegram_update_id' => $updates[1]->id, 'product_gallery_recipe_version_id' => $versions[1]->id]);
        foreach ([[$updates[0]->id, $versions[1]->id], [null, $versions[0]->id]] as [$update, $version]) {
            $result = json_decode((string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com', $update, $version))
                ->handle(new ToolRequest(['scope' => 'this_domain'])), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([$first->id], array_column($result['attempts'], 'id'));
        }
    }

    private function attempt(array $data = []): ProductSourceAttempt
    {
        return ProductSourceAttempt::create([...['domain' => 'example.com', 'product_url' => 'https://example.com/products/a',
            'actor' => 'server', 'phase' => 'gallery_training', 'action' => 'inspect', 'status' => 'interrupted'], ...$data]);
    }

    private function history(array $request = []): array
    {
        return json_decode((string) (new GetSourceAttemptHistory('https://example.com/products/a', 'example.com'))
            ->handle(new ToolRequest($request)), true, flags: JSON_THROW_ON_ERROR);
    }

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
