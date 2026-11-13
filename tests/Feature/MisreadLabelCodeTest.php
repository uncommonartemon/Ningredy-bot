<?php

namespace Tests\Feature;

use App\Services\Products\ProductResearchConfigurationGuard;
use Tests\TestCase;

/**
 * Decision of 2026-11-13, the colleague's #333: a memory module's label was read as
 * M471A1AG43MB1-CTD; no page showed it, every page of the matching module showed
 * M471A4G43MB1-CTD, and the research said so - but the rule that the operator's code stays the
 * card's code kept the misreading, and every right photo was rejected. A code read from a photo,
 * not typed by the operator, a few characters from the page code, may now give way to it.
 *
 * The fixture is the research's real answer for #333 (code fields and identity only).
 */
class MisreadLabelCodeTest extends TestCase
{
    private const string READ = 'Samsung memory module. MPN/model: M471A1AG43MB1-CTD. Label also reads: 32GB 2Rx8 PC4-2666V-SE1-11; Made in Korea; 2019.';

    public function test_the_real_answer_kept_the_misreading_because_the_rule_demanded_it(): void
    {
        $answer = $this->answer();

        $this->assertSame(['M471A1AG43MB1-CTD'], $answer['unconfirmed_operator_codes']);
        $this->assertSame('M471A4G43MB1-CTD', $answer['selected_identity']['observed_model']);
        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($answer, self::READ, ''));
    }

    public function test_a_misread_label_code_gives_way_to_the_page_code(): void
    {
        $answer = $this->byPageCode($this->answer());

        $this->assertSame([], app(ProductResearchConfigurationGuard::class)->issues($answer, self::READ, ''));
        $this->assertSame(['M471A1AG43MB1-CTD', 'M471A4G43MB1-CTD'], ProductResearchConfigurationGuard::misreadLabelCode($answer, ''));
    }

    public function test_without_the_reported_misreading_the_page_code_is_still_refused(): void
    {
        $answer = $this->byPageCode($this->answer());
        unset($answer['label_code_misread']);

        $this->assertNotSame([], app(ProductResearchConfigurationGuard::class)->issues($answer, self::READ, ''));
    }

    public function test_a_code_the_operator_typed_is_never_treated_as_a_misreading(): void
    {
        $answer = $this->byPageCode($this->answer());

        $this->assertNull(ProductResearchConfigurationGuard::misreadLabelCode($answer, 'найди M471A1AG43MB1-CTD'));
        $this->assertNotSame([], app(ProductResearchConfigurationGuard::class)->issues($answer, 'найди M471A1AG43MB1-CTD', 'найди M471A1AG43MB1-CTD'));
    }

    public function test_a_code_far_from_the_reading_is_another_product_not_a_misreading(): void
    {
        $answer = $this->byPageCode($this->answer(), 'M393A2K40DB3-CWE');

        $this->assertNull(ProductResearchConfigurationGuard::misreadLabelCode($answer, ''));
        $this->assertNotSame([], app(ProductResearchConfigurationGuard::class)->issues($answer, self::READ, ''));
    }

    /** @return array<string, mixed> */
    private function answer(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/research-misread-label-code.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The same answer as the new instruction asks: the product named by the page code, the
     * reading reported as misread and still listed for the operator.
     *
     * @return array<string, mixed>
     */
    private function byPageCode(array $answer, string $page = 'M471A4G43MB1-CTD'): array
    {
        $answer['model'] = $page;
        $answer['title'] = str_replace('M471A1AG43MB1-CTD', $page, (string) $answer['title']);
        $answer['specifications'] = array_map(fn (array $row): array => $row['key'] === 'mpn' ? [...$row, 'value' => $page] : $row, $answer['specifications']);
        $answer['label_code_misread'] = ['label_code' => 'M471A1AG43MB1-CTD', 'page_code' => $page,
            'reason' => 'Samsung has no 1AG43 density code; every page of the 32GB 2Rx8 PC4-2666 SO-DIMM shows the page code.'];

        return $answer;
    }
}
