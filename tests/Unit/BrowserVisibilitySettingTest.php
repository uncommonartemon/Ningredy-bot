<?php

namespace Tests\Unit;

use App\Services\Ai\AiSettings;
use App\Services\Products\ProductImageEncoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserVisibilitySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_browser_is_hidden_until_an_operator_says_otherwise(): void
    {
        // Visible is harder for a shop to detect, hidden is faster and quieter.
        // Which trade is right depends on the domain in front of you, so it is
        // a switch in the panel rather than a variable in a file - and the
        // default is the quiet one.
        $settings = app(AiSettings::class);

        $this->assertTrue($settings->browserHeadless());

        $settings->saveBrowserHeadless(false);
        $this->assertFalse(app(AiSettings::class)->browserHeadless());

        $settings->saveBrowserHeadless(true);
        $this->assertTrue(app(AiSettings::class)->browserHeadless());
    }

    public function test_the_renders_real_shops_serve_can_be_decoded(): void
    {
        // Live on dell.com: one frame of the gallery is 5000x5000 and was
        // turned away as unsafe while the rest went through. Frames are shrunk
        // to the storage edge the moment they are decoded now, so the full
        // bitmap exists for an instant rather than for the length of a gallery.
        $encoder = app(ProductImageEncoder::class);

        $this->assertTrue($encoder->isSafeToDecode(5000, 5000), 'The Dell frame that was rejected.');
        $this->assertTrue($encoder->isSafeToDecode(6000, 6000));
        // Still a ceiling: 64 megapixels is a quarter of a gigabyte at the peak.
        $this->assertFalse($encoder->isSafeToDecode(8000, 8000));
    }
}
