<?php

namespace Tests\Unit;

use App\Services\Products\ProductImageStorage;
use Tests\TestCase;

/**
 * Live report 2026-11-27, #690 (Vision run 3875): four of istyle.cz's five frames were left out
 * only because no code could be read on them - the reviewer had taken each for the exact
 * product - and the journal called that "drop_non_product_or_foreign_text_frames".
 */
class FrameRejectionClassTest extends TestCase
{
    public function test_a_frame_left_out_by_the_label_rule_is_journaled_as_such(): void
    {
        // Frame 2 of run 3875 as the server saw it after its own label comparison.
        $frame = ['index' => 2, 'exact_match' => false, 'reviewer_exact_match' => true, 'color_match' => true, 'physical_match' => 'match',
            'catalog_photo' => true, 'publishable' => true, 'rejection_reason' => 'none', 'kind' => 'product', 'view' => 'side',
            'observed_marking' => '', 'marking_verdict' => 'none', 'score' => 68];

        $this->assertSame('no_readable_label', ProductImageStorage::frameRejectionClass($frame));
        $this->assertSame('other_code_on_label', ProductImageStorage::frameRejectionClass([...$frame, 'marking_verdict' => 'conflict', 'observed_marking' => 'M321R2GA3EB0-CWMCH']));
        $this->assertSame('refused_banner', ProductImageStorage::frameRejectionClass([...$frame, 'publishable' => false, 'rejection_reason' => 'banner']));
        $this->assertSame('not_a_catalog_photo', ProductImageStorage::frameRejectionClass([...$frame, 'catalog_photo' => false]));
        $this->assertSame('not_selected', ProductImageStorage::frameRejectionClass([...$frame, 'exact_match' => true]));
    }
}
