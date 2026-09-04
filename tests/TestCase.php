<?php

namespace Tests;

use App\Ai\Agents\GalleryTextLanguageAgent;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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

        GalleryTextLanguageAgent::fake(fn (): array => [
            'foreign_text_frames' => [],
            'reason' => 'Иностранного текста не найдено.',
        ]);
    }
}
