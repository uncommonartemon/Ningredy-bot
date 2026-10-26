<?php

namespace Tests\Unit;

use App\Services\Photos\ImageRenditionProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cases live in tests/Fixtures/rendition-probe-cases.json, shared with the
 * browser's copy of the rules (tests/Node/rendition-probe.test.mjs): one list
 * of addresses dropped as too small over the live searches, two implementations.
 */
class ImageRenditionProbeTest extends TestCase
{
    #[DataProvider('thumbnails')]
    public function test_a_thumbnail_address_names_where_its_larger_rendition_is(string $thumbnail, ?string $expectedFirst, array $alsoExpected, ?int $width, ?int $height): void
    {
        $guesses = ImageRenditionProbe::largerRenditions($thumbnail, 6, $width, $height);

        if ($expectedFirst === null) {
            $this->assertSame([], $guesses, implode("\n", $guesses));

            return;
        }
        $this->assertSame($expectedFirst, $guesses[0] ?? null, implode("\n", $guesses));
        foreach ($alsoExpected as $guess) {
            $this->assertContains($guess, $guesses, implode("\n", $guesses));
        }
        $this->assertNotContains($thumbnail, $guesses);
        $this->assertLessThanOrEqual(6, count($guesses));
    }

    public static function thumbnails(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/rendition-probe-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($cases as $case) {
            $rows[$case['name']] = [$case['thumbnail'], $case['first'], $case['also'] ?? [], $case['width'] ?? null, $case['height'] ?? null];
        }

        return $rows;
    }

    public function test_an_address_that_is_not_a_url_gives_no_guesses(): void
    {
        $this->assertSame([], ImageRenditionProbe::largerRenditions('not a url'));
    }
}
