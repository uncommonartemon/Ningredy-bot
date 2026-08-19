<?php

namespace Tests\Unit;

use App\Services\Products\ProductPageLayoutFingerprint;
use Tests\TestCase;

class ProductPageLayoutFingerprintTest extends TestCase
{
    public function test_text_and_order_do_not_change_layout_fingerprint(): void
    {
        $service = app(ProductPageLayoutFingerprint::class);
        $first = $service->make([
            'interactive_controls' => [
                ['selector' => '.gallery-next', 'text' => 'Next'],
                ['selector' => '.thumb'],
            ],
            'image_candidates' => [
                ['selector' => '.hero img', 'parent_control_selector' => '.hero'],
            ],
        ]);
        $second = $service->make([
            'image_candidates' => [
                ['selector' => '.hero img', 'parent_control_selector' => '.hero'],
            ],
            'interactive_controls' => [
                ['selector' => '.thumb'],
                ['selector' => '.gallery-next', 'text' => 'Další'],
            ],
        ]);

        $this->assertSame($first, $second);
        $this->assertNotNull($first);
    }

    public function test_different_gallery_structure_has_a_different_fingerprint(): void
    {
        $service = app(ProductPageLayoutFingerprint::class);

        $this->assertNotSame(
            $service->make(['interactive_controls' => [['selector' => '.gallery-next']]]),
            $service->make(['interactive_controls' => [['selector' => '[data-swiper-next]']]]),
        );
    }
}
