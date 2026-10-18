<?php

namespace Tests\Unit;

use App\Services\Products\PhotoSourceNotice;
use App\Services\Products\ProductPhotoKeyboardContext;
use PHPUnit\Framework\TestCase;

class ProductPhotoKeyboardContextTest extends TestCase
{
    public function test_late_product_layout_preserves_unknown_pixels_and_adds_an_honest_notice(): void
    {
        $source = $this->source();
        $result = ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'));
        $this->assertSame($source['_photo_visual_review']['frames'], $result['_photo_visual_review']['frames']);
        $this->assertSame([], ProductPhotoKeyboardContext::pendingSources($result));
        $notice = PhotoSourceNotice::russian($result);
        $this->assertStringContainsString('не сверена', $notice);
        $this->assertStringContainsString('Italian', $notice);
        $this->assertStringNotContainsString('US', $notice);
        $this->assertSame($result, ProductPhotoKeyboardContext::reconcile($result, $this->layout('Italian')));
    }

    public function test_changed_target_does_not_reuse_a_czech_product_notice_for_an_italian_product(): void
    {
        $source = $this->source();
        $note = 'На фото US; у товара чешская раскладка.';
        $source['_photo_visual_review']['keyboard_target'] = ['Czech'];
        $source['_photo_visual_review']['differences'] = [$note, 'Unrelated physical detail.'];
        $source['_photo_visual_review']['frames'][0]['keyboard_layout_note'] = $note;
        $source['_photo_visual_review']['frames'][0]['keyboard_requirement_scope'] = 'product';
        $result = ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'));
        $notice = PhotoSourceNotice::russian($result);
        $this->assertStringNotContainsString('чешская', $notice);
        $this->assertStringContainsString('Italian', $notice);
        $this->assertStringContainsString('Unrelated physical detail', $notice);
        $this->assertSame($note, $result['_photo_visual_review']['frames'][0]['keyboard_layout_note']);
    }

    public function test_explicit_photo_requirement_keeps_unchecked_visible_keys_pending_but_not_closed_views(): void
    {
        $source = $this->source();
        $source['_photo_visual_review']['frames'][] = ['source_url' => 'closed.jpg', 'keyboard_visibility' => 'not_visible'];
        $input = ['input_evidence' => [['key' => 'photo_keyboard_layout', 'value' => 'Italian', 'source_kind' => 'user_text']]];
        $result = ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'), $input);
        $this->assertSame(['front.jpg'], ProductPhotoKeyboardContext::pendingSources($result));
        $this->assertSame('not_applicable', $result['_photo_visual_review']['frames'][0]['keyboard_layout_match']);
    }

    public function test_a_current_strict_photo_match_is_not_revoked_by_a_late_product_fact(): void
    {
        $source = $this->source();
        $source['_photo_visual_review']['frames'][0]['keyboard_requirement_scope'] = 'photos';
        $source['_photo_visual_review']['frames'][0]['keyboard_layout_match'] = 'match';
        $source['_photo_visual_review']['photo_requirement'] = ProductPhotoKeyboardContext::photoRequirement([]);
        $result = ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'));
        $this->assertSame([], ProductPhotoKeyboardContext::pendingSources($result));
    }

    public function test_changed_or_legacy_strict_photo_target_cannot_reuse_a_previous_match(): void
    {
        $source = $this->source();
        $source['_photo_visual_review']['frames'][0]['keyboard_requirement_scope'] = 'photos';
        $source['_photo_visual_review']['frames'][0]['keyboard_layout_match'] = 'match';
        $input = ['original_operator_request' => 'Photos must show an Italian keyboard'];
        $this->assertSame(['front.jpg'], ProductPhotoKeyboardContext::pendingSources(
            ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'), $input),
        ));
        $source['_photo_visual_review']['photo_requirement'] = ProductPhotoKeyboardContext::photoRequirement([
            'original_operator_request' => 'Photos must show a Czech keyboard',
        ]);
        $this->assertSame(['front.jpg'], ProductPhotoKeyboardContext::pendingSources(
            ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian'), $input),
        ));
    }

    public function test_unchanged_target_keeps_the_current_verified_note_and_does_not_create_extra_work(): void
    {
        $source = $this->source();
        $source['_photo_visual_review']['keyboard_target'] = ['Italian'];
        $source['_photo_visual_review']['frames'][0]['keyboard_requirement_scope'] = 'product';
        $this->assertSame($source, ProductPhotoKeyboardContext::reconcile($source, $this->layout('Italian')));
        $this->assertSame([], ProductPhotoKeyboardContext::reconcile([], $this->layout('Italian')));
    }

    private function source(): array
    {
        return ['_photo_applicability' => ['verdict' => 'exact'], '_photo_visual_review' => [
            'scope' => 'current_downloaded_frames', 'keyboard_target' => [], 'differences' => [],
            'frames' => [['source_url' => 'front.jpg', 'physical_match' => 'match', 'color_match' => true,
                'keyboard_visibility' => 'readable', 'keyboard_requirement_scope' => 'none',
                'keyboard_layout_match' => 'not_applicable', 'keyboard_layout' => '', 'keyboard_layout_note' => '']],
        ]];
    }

    private function layout(string $value): array
    {
        return [['key' => 'keyboard_layout', 'name' => 'Keyboard layout', 'value' => $value]];
    }
}
