<?php

namespace Tests\Unit;

use App\Services\Products\SourceIdentityProvenance;
use PHPUnit\Framework\TestCase;

class SourceIdentityProvenanceTest extends TestCase
{
    private const RECORD = 'Samsung 990 PRO 2 TB Part number: MZ-V9P2T0BW GTIN: 8806094215038';

    public function test_a_page_look_adds_to_a_code_matched_record_but_replaces_an_ordinary_page(): void
    {
        $record = $this->record();
        $page = ['url' => 'https://shop.example/990-pro', '_preflight_identity_evidence' => 'Old search title'];

        $this->assertSame(self::RECORD."\nMaking sure you're not a bot!", SourceIdentityProvenance::mergeEvidence($record, "Making sure you're not a bot!"));
        $this->assertSame(self::RECORD, SourceIdentityProvenance::mergeEvidence($record, ''));
        $this->assertSame(self::RECORD, SourceIdentityProvenance::mergeEvidence($record, 'Part number: MZ-V9P2T0BW'));
        $this->assertSame('Samsung 990 PRO page', SourceIdentityProvenance::mergeEvidence($page, 'Samsung 990 PRO page'));
        $this->assertSame('', SourceIdentityProvenance::mergeEvidence($page, null));
    }

    public function test_a_code_matched_record_keeps_its_own_address_through_a_redirect(): void
    {
        $wrapper = 'https://icecat.biz/.within.website/?redir=%2Fp%2Fsamsung%2Fmz-v9p2t0bw%2F990-pro-1.html';

        $this->assertSame($this->record()['url'], SourceIdentityProvenance::pageUrl($this->record(), $wrapper));
        $this->assertSame($wrapper, SourceIdentityProvenance::pageUrl(['url' => 'https://shop.example/990-pro'], $wrapper));
    }

    public function test_only_a_code_match_counts_and_only_its_marks_travel_into_page_contexts(): void
    {
        $this->assertTrue(SourceIdentityProvenance::confirmedByCode($this->record()));
        $this->assertFalse(SourceIdentityProvenance::confirmedByCode(['url' => 'https://shop.example/990-pro', '_official_gallery' => true]));
        $this->assertFalse(SourceIdentityProvenance::confirmedByCode([SourceIdentityProvenance::KEY => ['kind' => 'search_title']]));
        $this->assertSame(['_official_gallery', SourceIdentityProvenance::KEY], array_keys(SourceIdentityProvenance::carry($this->record())));
        $this->assertSame(['MZ-V9P2T0BW', '8806094215038'], SourceIdentityProvenance::codeMatch('icecat', ['MZ-V9P2T0BW', '', '8806094215038'])['codes']);
    }

    public function test_a_record_with_its_own_gallery_is_read_from_the_catalogue_not_from_its_page(): void
    {
        // Operator, 2026-10-30: the record comes through the API; the page is not the source.
        $this->assertTrue(SourceIdentityProvenance::galleryFromRecord($this->record()));
        $this->assertFalse(SourceIdentityProvenance::galleryFromRecord([...$this->record(), 'image_urls' => []]));
        $this->assertFalse(SourceIdentityProvenance::galleryFromRecord([...$this->record(), 'image_urls' => ['', ' ']]));
        $this->assertFalse(SourceIdentityProvenance::galleryFromRecord(['url' => 'https://shop.example/990-pro', '_official_gallery' => true,
            'image_urls' => ['https://shop.example/a.jpg']]));
    }

    public function test_a_record_the_research_named_is_read_from_the_catalogue_but_is_not_a_code_match(): void
    {
        // Operator, 2026-10-30: a datasheet found by name is read by its number; its photos are checked like a page's.
        $named = [...$this->record(), SourceIdentityProvenance::KEY => SourceIdentityProvenance::catalogRecord('icecat', '777')];

        $this->assertFalse(SourceIdentityProvenance::confirmedByCode($named));
        $this->assertTrue(SourceIdentityProvenance::fromCatalog($named));
        $this->assertTrue(SourceIdentityProvenance::galleryFromRecord($named));
        $this->assertSame(self::RECORD, SourceIdentityProvenance::mergeEvidence($named, ''));
        $this->assertSame($named['url'], SourceIdentityProvenance::pageUrl($named, 'https://icecat.biz/.within.website/?redir=x'));
        $this->assertFalse(SourceIdentityProvenance::fromCatalog(['url' => 'https://shop.example/990-pro', '_official_gallery' => true]));
    }

    private function record(): array
    {
        return ['url' => 'https://icecat.biz/p/samsung/mz-v9p2t0bw/990-pro-1.html', 'title' => 'Icecat datasheet: Samsung 990 PRO 2 TB',
            'image_urls' => ['https://images.icecat.biz/img/gallery/a.jpg'], '_official_gallery' => true,
            SourceIdentityProvenance::KEY => SourceIdentityProvenance::codeMatch('icecat', ['MZ-V9P2T0BW', '8806094215038']),
            '_preflight_identity_evidence' => self::RECORD];
    }
}
