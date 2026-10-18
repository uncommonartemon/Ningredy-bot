<?php

namespace Tests;

use App\Ai\Agents\GalleryFrameReviewAgent;
use App\Ai\Agents\GalleryTextLanguageAgent;
use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Ai\Agents\ProductSourceIdentityAgent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\IsolatedEnvironment;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Protect direct diagnostic TestCase use outside the PHPUnit CLI too.
        IsolatedEnvironment::prepare();
        $app = parent::createApplication();
        IsolatedEnvironment::assertDatabase($app);

        // RefreshDatabase isolates rows, not files. Install isolation before
        // test traits run: catalog cleanup must never see the real media disk.
        $root = storage_path('framework/testing/application-disks/'.getmypid().'/'.bin2hex(random_bytes(8)));
        foreach ($app['config']->get('filesystems.disks', []) as $name => $disk) {
            if (($disk['driver'] ?? null) === 'local') {
                $app['config']->set("filesystems.disks.{$name}.root", $root.'/'.$name);
                $app['filesystem']->forgetDisk($name);
            }
        }

        return $app;
    }

    /**
     * The language gate runs on every gallery accepted as a set, which is a
     * path a great many tests reach. Left unfaked it made a real API call from
     * each of them - the suite went from two minutes to thirty-five, waiting on
     * timeouts. It answers "nothing foreign found" here so a test says nothing
     * about language unless it means to; any test that cares overrides it with
     * its own fake, exactly as it would for any other agent.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // A missed fake must fail locally, never become a paid provider call
        // or a real Telegram message. Network canaries opt in to their own URL.
        Http::preventStrayRequests();

        // Identity checks run in both source queues. A regression test must
        // never become a paid provider call; tests of the judge override this.
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'uncertain', 'confidence' => 0.5, 'reason' => 'No identity verdict configured by this test.',
        ])->preventStrayPrompts();

        ProductPhotoApplicabilityAgent::fake(fn (): array => [
            'verdict' => 'uncertain', 'reason' => 'No photo applicability verdict configured by this test.',
            'source_model' => '', 'differences' => [], 'evidence' => [],
            'plausible_target' => true, 'may_inspect_gallery' => true,
            'physical_checks' => array_fill_keys(['platform', 'revision', 'construction', 'dimensions', 'ports', 'color'],
                ['status' => 'unknown', 'evidence' => '']),
        ])->preventStrayPrompts();

        GalleryTextLanguageAgent::fake(fn (): array => [
            'foreign_text_frames' => [],
            'reason' => 'Иностранного текста не найдено.',
        ]);

        GalleryFrameReviewAgent::fake(fn (): array => [
            'foreign_text_frames' => [], 'non_product_frames' => [], 'uncertain_content_frames' => [],
            'reason' => 'Product frames, no foreign text (test fixture).',
        ])->preventStrayPrompts();
    }
}
