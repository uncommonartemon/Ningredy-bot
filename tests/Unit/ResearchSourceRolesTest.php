<?php

namespace Tests\Unit;

use App\Services\ProductSearch\Research\ResearchSourceRoles;
use Tests\TestCase;

class ResearchSourceRolesTest extends TestCase
{
    public function test_legacy_candidates_keep_their_existing_eligibility_without_claiming_verification(): void
    {
        $source = ['url' => 'https://shop.example/product', 'type' => 'retailer'];
        $this->assertTrue(ResearchSourceRoles::maySupplyFacts($source));
        $this->assertTrue(ResearchSourceRoles::maySupplyPhotos($source));
        $this->assertTrue(ResearchSourceRoles::mayIdentifySelectedProduct($source));
        $this->assertArrayNotHasKey('_photo_applicability', $source);
    }

    public function test_text_only_and_compatible_photo_candidates_have_separate_purposes(): void
    {
        $facts = ['roles' => ['facts']];
        $compatible = ['roles' => ['compatible_photo_candidate']];
        $this->assertFalse(ResearchSourceRoles::maySupplyPhotos($facts));
        $this->assertTrue(ResearchSourceRoles::mayIdentifySelectedProduct($facts));
        $this->assertTrue(ResearchSourceRoles::maySupplyPhotos($compatible));
        $this->assertFalse(ResearchSourceRoles::maySupplyFacts($compatible));
        $this->assertFalse(ResearchSourceRoles::mayIdentifySelectedProduct($compatible));
        $this->assertFalse(ResearchSourceRoles::mayIdentifySelectedProduct(['roles' => ['facts', 'compatible_photo_candidate']]));
        $this->assertTrue(ResearchSourceRoles::mayIdentifySelectedProduct(['roles' => ['facts', 'exact_photo']]));
        $this->assertFalse(ResearchSourceRoles::maySupplyPhotos(['roles' => []]));
    }
}
