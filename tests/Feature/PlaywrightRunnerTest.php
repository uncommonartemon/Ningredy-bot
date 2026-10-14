<?php

namespace Tests\Feature;

use App\Services\Ai\AiSettings;
use App\Services\Playwright\BrowserRunRequest;
use App\Services\Playwright\PlaywrightRunner;
use App\Services\Products\BrowserProductImageTransferStore;
use Mockery;
use Tests\TestCase;

class PlaywrightRunnerTest extends TestCase
{
    private BrowserProductImageTransferStore $transfers;

    private PlaywrightRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['product-images.browser_fallback.script' => 'tests/Fixtures/playwright-runner.mjs']);
        $settings = Mockery::mock(AiSettings::class);
        $settings->shouldReceive('imageMinimumWidth')->andReturn(700);
        $settings->shouldReceive('imageMinimumHeight')->andReturn(0);
        $settings->shouldReceive('browserHeadless')->andReturn(true);
        $this->transfers = new BrowserProductImageTransferStore;
        $this->runner = new PlaywrightRunner($settings, $this->transfers);
    }

    public function test_real_process_receives_context_and_recipe_without_truncating_replay_gallery(): void
    {
        $recipe = ['collect_selectors' => ['.gallery img'], 'actions' => [['kind' => 'click', 'selector' => '.next']]];
        $result = $this->runner->run(new BrowserRunRequest(
            'https://shop.example/normal', $recipe, 2, false, 60,
            ['observation_focus_selector' => '#viewer', 'minimum_image_width' => 950, 'minimum_image_height' => 300],
            true, 'shop.example',
        ));
        $this->assertTrue($result->hasObservation);
        $this->assertCount(3, $result->payload['images'], 'A replay must not cap candidates at the output photo limit.');
        $fixture = $result->payload['fixture'];
        $this->assertSame($recipe, $fixture['recipe']);
        $this->assertSame('#viewer', $fixture['focus']);
        $this->assertSame('shop.example', $fixture['host']);
        $this->assertSame('48000', $fixture['deadline']);
        $this->assertSame('950', $fixture['width']);
        $this->assertSame('300', $fixture['height']);
        $this->assertSame('true', $fixture['http11']);
        $this->assertSame('0', $fixture['scout']);
        $this->assertDirectoryDoesNotExist($fixture['directory']);
    }

    public function test_scout_and_unplanned_extraction_keep_their_candidate_limit(): void
    {
        foreach ([[true, ['pre_click_selectors' => ['#tab']]], [false, []]] as [$scout, $recipe]) {
            $result = $this->runner->run(new BrowserRunRequest('https://shop.example/normal', $recipe, 2, $scout, 10));
            $this->assertCount(2, $result->payload['images']);
            $this->assertDirectoryDoesNotExist($result->payload['fixture']['directory']);
        }
    }

    public function test_transferred_bytes_and_screenshot_survive_temporary_directory_cleanup(): void
    {
        $result = $this->runner->run(new BrowserRunRequest('https://shop.example/normal', [], 20, true, 10));
        $download = $this->transfers->get('https://images.example/front.png', 10000);
        $this->assertNotNull($download);
        $this->assertSame('image/png', $download['mime_type']);
        $this->assertSame($download['bytes'], $result->payload['screenshot']);
        $this->assertArrayNotHasKey('transferred_images', $result->payload);
        $this->assertArrayNotHasKey('screenshot_path', $result->payload);
        $this->assertDirectoryDoesNotExist($result->payload['fixture']['directory']);
    }

    public function test_invalid_protocol_and_process_failure_are_not_successful_observations(): void
    {
        foreach (['invalid' => 'browser_protocol', 'crash' => 'browser_process'] as $mode => $failure) {
            $result = $this->runner->run(new BrowserRunRequest('https://shop.example/'.$mode, [], 20, true, 10));
            $this->assertFalse($result->hasObservation);
            $this->assertSame([], $result->payload['images']);
            $this->assertSame($failure, $result->payload['failure_kind']);
        }
    }

    public function test_timeout_keeps_checkpoint_evidence_without_publishing_unverified_images(): void
    {
        $result = $this->runner->run(new BrowserRunRequest('https://shop.example/timeout', [], 20, true, 2));
        $this->assertFalse($result->hasObservation);
        $this->assertSame('browser_timeout', $result->payload['failure_kind']);
        $this->assertSame([], $result->payload['images']);
        $this->assertSame('Checkpoint', $result->payload['scout']['title']);
        $this->assertTrue($result->payload['diagnostics']['partial']);
        $this->assertNotEmpty($result->payload['screenshot']);
        $this->assertTrue($result->payload['action_trace'][0]['clicked']);
        $this->assertDirectoryDoesNotExist($result->payload['scout']['fixture_directory']);
    }
}
