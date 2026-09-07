<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The third verdict, made real.
 *
 * A navigation can end somewhere neither page identifies: no sku, no canonical,
 * no og:url on either side. The browser reports that as identity_unconfirmed
 * rather than guessing, and until now nothing read it - the frames arrived at
 * acceptance looking exactly like frames from a page we had confirmed.
 *
 * confirmed_gallery is the badge that lets a frame skip ahead of the checks
 * that would catch a wrong product. A frame from a page we could not identify
 * is precisely the frame those checks exist for.
 */
class UnconfirmedIdentityIsNotTrustedTest extends TestCase
{
    private const FRAME = 'https://cdn.example/gallery/laptop-front.jpg';

    public function test_a_frame_from_an_unidentified_page_is_not_confirmed(): void
    {
        $extractor = app(BrowserProductGalleryExtractor::class);

        $this->note($extractor, ['images' => [self::FRAME], 'diagnostics' => ['identity_unconfirmed' => true]]);
        $this->confirm($extractor, [self::FRAME]);

        $this->assertFalse(
            $extractor->isConfirmedGalleryImage(self::FRAME),
            'Unknown identity must not buy the badge that skips verification.',
        );
    }

    public function test_the_frame_is_kept_rather_than_thrown_away(): void
    {
        // Losing it would punish every shop that publishes no metadata, and the
        // agent chose the control that led there. It goes through Vision like
        // any ordinary candidate instead.
        $extractor = app(BrowserProductGalleryExtractor::class);

        $this->note($extractor, ['images' => [self::FRAME], 'diagnostics' => ['identity_unconfirmed' => true]]);
        $partial = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'rememberPartialGalleryImages');
        $partial->invoke($extractor, [self::FRAME]);

        $this->assertTrue($extractor->isPartialGalleryImage(self::FRAME));
    }

    public function test_an_identified_page_still_confirms_normally(): void
    {
        $extractor = app(BrowserProductGalleryExtractor::class);

        $this->note($extractor, ['images' => [self::FRAME], 'diagnostics' => ['identity_unconfirmed' => false]]);
        $this->confirm($extractor, [self::FRAME]);

        $this->assertTrue($extractor->isConfirmedGalleryImage(self::FRAME));
    }

    public function test_a_result_that_says_nothing_about_identity_is_unaffected(): void
    {
        // Every run before this field existed, and every run that never left the
        // product page.
        $extractor = app(BrowserProductGalleryExtractor::class);

        $this->note($extractor, ['images' => [self::FRAME], 'diagnostics' => []]);
        $this->confirm($extractor, [self::FRAME]);

        $this->assertTrue($extractor->isConfirmedGalleryImage(self::FRAME));
    }

    public function test_only_the_frames_of_that_run_lose_the_badge(): void
    {
        $other = 'https://cdn.example/gallery/laptop-side.jpg';
        $extractor = app(BrowserProductGalleryExtractor::class);

        $this->note($extractor, ['images' => [self::FRAME], 'diagnostics' => ['identity_unconfirmed' => true]]);
        $this->confirm($extractor, [self::FRAME, $other]);

        $this->assertFalse($extractor->isConfirmedGalleryImage(self::FRAME));
        $this->assertTrue($extractor->isConfirmedGalleryImage($other));
    }

    public function test_the_operator_is_told_which_it_is(): void
    {
        // "Nothing was collected" and "we could not tell whose page that was"
        // are different situations and must not read alike in the log.
        $messages = [];
        $this->note(
            app(BrowserProductGalleryExtractor::class),
            ['images' => [self::FRAME], 'diagnostics' => ['identity_unconfirmed' => true]],
            function (string $level, string $message) use (&$messages): void {
                $messages[] = $message;
            },
        );

        $this->assertNotEmpty(array_filter(
            $messages,
            fn (string $message): bool => str_contains($message, 'не удалось опознать'),
        ));
    }

    /** @param array<string, mixed> $result */
    private function note(BrowserProductGalleryExtractor $extractor, array $result, ?callable $debug = null): void
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'noteIdentityUnconfirmed');
        $method->invoke($extractor, $result, $debug);
    }

    /** @param array<int, string> $images */
    private function confirm(BrowserProductGalleryExtractor $extractor, array $images): void
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'rememberConfirmedGalleryImages');
        $method->invoke($extractor, $images);
    }
}
