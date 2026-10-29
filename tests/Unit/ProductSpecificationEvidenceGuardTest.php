<?php

namespace Tests\Unit;

use App\Services\Products\ProductSpecificationEvidenceGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductSpecificationEvidenceGuardTest extends TestCase
{
    private function fact(array $changes = []): array
    {
        return [...['key' => 'ram', 'name' => 'RAM', 'value' => '64 GB',
            'source_url' => 'https://maker.example/specs.pdf', 'source_excerpt' => 'Installed RAM: 64 GB'], ...$changes];
    }

    public function test_an_existing_cited_fact_survives_an_empty_photo_page_without_a_new_fetch(): void
    {
        $fact = $this->fact();
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'evidence' => 'confirmed']], [], []);
        $this->addToAssertionCount(1);
    }

    public function test_a_table_quoted_with_its_own_separators_and_row_order_is_still_observed(): void
    {
        // Request #99: gpt-6-luna quoted rows the page lists apart, with tabs.
        $page = ['url' => 'https://shop.example/dell', 'specification_text' => "Processor Model: 155H\nProcessor Brand: Intel\nProcessor Type: Core Ultra 7"];
        $cpu = ['key' => 'cpu', 'name' => 'Processor', 'value' => 'Intel Core Ultra 7 155H', 'evidence' => 'confirmed',
            'source_url' => 'https://shop.example/dell', 'source_excerpt' => "Processor Type\tCore Ultra 7\nProcessor Model\t155H"];

        (new ProductSpecificationEvidenceGuard)->validate([], [$cpu], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_two_rows_joined_by_a_semicolon_are_each_observed(): void
    {
        $page = ['url' => 'https://shop.example/zenbook', 'specification_text' => "Display\n3K (2880 x 1800) 120Hz OLED 16:10 aspect ratio\nWeight\n1.2 kg\nTouch Screen\nYes"];

        (new ProductSpecificationEvidenceGuard)->validate([], [['key' => 'display', 'name' => 'Display', 'value' => '14-inch OLED touchscreen',
            'evidence' => 'confirmed', 'source_url' => 'https://shop.example/zenbook',
            'source_excerpt' => '3K (2880 x 1800) 120Hz OLED 16:10 aspect ratio; Touch Screen: Yes']], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_a_changed_number_is_not_observed_although_only_punctuation_differs(): void
    {
        // A reviewer's reproduction: the page says 2.5 kg, the quote 25 kg.
        $page = ['url' => 'https://shop.example/p', 'specification_text' => "Weight: 2.5 kg\nDisplay: 1 6 inch"];
        $guard = new ProductSpecificationEvidenceGuard;

        foreach (['Weight: 25 kg', 'Display: 16 inch'] as $quote) {
            try {
                $guard->validate([], [['key' => 'x', 'name' => 'X', 'value' => 'x', 'evidence' => 'confirmed',
                    'source_url' => 'https://shop.example/p', 'source_excerpt' => $quote]], [], [$page]);
                $this->fail("{$quote} was accepted against the page");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('excerpt was not observed', $exception->getMessage());
            }
        }

        $guard->validate([], [['key' => 'weight', 'name' => 'Weight', 'value' => '2.5 kg', 'evidence' => 'confirmed',
            'source_url' => 'https://shop.example/p', 'source_excerpt' => "Weight\t2.5 kg"]], [], [$page]);
    }

    #[DataProvider('changedNumericQuotes')]
    public function test_a_quote_cannot_change_or_cut_a_number(string $pageText, string $quote): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');

        (new ProductSpecificationEvidenceGuard)->validate([], [['key' => 'x', 'value' => 'x', 'evidence' => 'confirmed',
            'source_url' => 'https://shop.example/p', 'source_excerpt' => $quote]], [], [
                ['url' => 'https://shop.example/p', 'specification_text' => $pageText],
            ]);
    }

    public static function changedNumericQuotes(): array
    {
        return [
            'raw decimal suffix' => ['Weight: 2.5 kg', '5 kg'],
            'one-digit newline fragment' => ['Weight: 2.5 kg', "5\nkg"],
            'one-digit semicolon fragment' => ['Weight: 2.5 kg', '5; kg'],
            'normalized decimal suffix' => ['Weight: 2.5 kg', "5\tkg"],
            'raw whole-number suffix' => ['Weight: 12.5 kg', '2.5 kg'],
            'normalized whole-number suffix' => ['Weight: 12.5 kg', "2.5\tkg"],
            'raw decimal prefix' => ['Weight: 2.5 kg', 'Weight: 2'],
            'normalized decimal prefix' => ['Weight: 2.5 kg', "Weight\t2"],
            'raw integer suffix' => ['RAM: 64 GB', '4 GB'],
            'raw integer prefix' => ['RAM: 64 GB', 'RAM: 6'],
            'decimal comma suffix' => ['Weight: 2,5 kg', "5\tkg"],
            'decimal comma removed' => ['Weight: 2,5 kg', 'Weight: 25 kg'],
            'negative sign removed' => ['Voltage: -12 V', 'Voltage: 12 V'],
            'raw negative suffix' => ['Voltage: -12 V', '12 V'],
            'normalized negative suffix' => ['Voltage: -12 V', "12\tV"],
            'negative after colon without space' => ['Voltage:-12 V', '12 V'],
            'normalized negative after colon' => ['Voltage:-12 V', "12\tV"],
            'negative after colon removed with label' => ['Voltage:-12 V', "Voltage\t12 V"],
            'positive sign removed' => ['Voltage: +12 V', '12 V'],
            'normalized positive sign removed' => ['Voltage: +12 V', "12\tV"],
            'normalized positive after colon' => ['Voltage:+12 V', "12\tV"],
            'sign changed' => ['Voltage: -12 V', 'Voltage: +12 V'],
            'unicode minus removed' => ["Voltage: \u{2212}12 V", '12 V'],
            'normalized unicode minus removed' => ["Voltage: \u{2212}12 V", "12\tV"],
            'normalized unicode minus after colon' => ["Voltage:\u{2212}12 V", "12\tV"],
            'leading decimal point removed' => ['Weight: .5 kg', 'Weight: 5 kg'],
            'adjacent numbers joined' => ['Display: 1 6 inch', 'Display: 16 inch'],
        ];
    }

    #[DataProvider('unchangedNumericQuotes')]
    public function test_whole_numeric_quotes_still_allow_short_excerpts_and_formatting(string $pageText, string $quote): void
    {
        (new ProductSpecificationEvidenceGuard)->validate([], [['key' => 'x', 'value' => 'x', 'evidence' => 'confirmed',
            'source_url' => 'https://shop.example/p', 'source_excerpt' => $quote]], [], [
                ['url' => 'https://shop.example/p', 'specification_text' => $pageText],
            ]);
        $this->addToAssertionCount(1);
    }

    public static function unchangedNumericQuotes(): array
    {
        return [
            'raw short decimal' => ['Weight: 2.5 kg', '2.5 kg'],
            'short hyphenated cpu code' => ['CPU: Intel Core i7-13620H', '13620H'],
            'normalized short hyphenated cpu code' => ['CPU: Intel Core i7-13620H', "13620\tH"],
            'normalized whole hyphenated cpu code' => ['CPU: Intel Core i7-13620H', 'Intel Core i7 13620H'],
            'model hyphen separator' => ['Model: X-123', 'Model: X 123'],
            'model hyphen and tab separators' => ['Model:X-123', "Model\tX\t123"],
            'normalized short decimal' => ['Weight: 2.5 kg', "2.5\tkg"],
            'raw short integer' => ['Weight: 5 kg', '5 kg'],
            'whole one-digit newline fragment' => ['Weight: 5 kg', "5\nkg"],
            'whole one-digit semicolon fragment' => ['Weight: 5 kg', '5; kg'],
            'normalized short integer' => ['Weight: 5 kg', "5\tkg"],
            'negative sign retained' => ['Voltage: -12 V', "Voltage\t-12 V"],
            'negative after colon retained' => ['Voltage:-12 V', "Voltage\t-12 V"],
            'short negative number' => ['Voltage: -12 V', "-12\tV"],
            'positive sign retained' => ['Voltage: +12 V', "+12\tV"],
            'positive after colon retained' => ['Voltage:+12 V', "Voltage\t+12 V"],
            'unicode minus retained' => ["Voltage: \u{2212}12 V", "\u{2212}12\tV"],
            'unicode minus after colon retained' => ["Voltage:\u{2212}12 V", "Voltage\t\u{2212}12 V"],
            'decimal comma retained' => ['Weight: 2,5 kg', "2,5\tkg"],
            'leading decimal retained' => ['Weight: .5 kg', "Weight\t.5 kg"],
            'adjacent numbers retained' => ['Display: 1 6 inch', "Display\t1 6 inch"],
            'later whole raw occurrence' => ['Weight: 2.5 kg; Package: 5 kg', '5 kg'],
            'later whole normalized occurrence' => ['Weight: 2.5 kg; Package: 5 kg', "5\tkg"],
        ];
    }

    public function test_words_the_page_does_not_have_are_still_not_observed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');
        $page = ['url' => 'https://shop.example/dell', 'specification_text' => "Processor Type: Core Ultra 7\nProcessor Model: 155H"];

        (new ProductSpecificationEvidenceGuard)->validate([], [['key' => 'cpu', 'name' => 'Processor', 'value' => 'Intel Core Ultra 7 155H',
            'evidence' => 'confirmed', 'source_url' => 'https://shop.example/dell',
            'source_excerpt' => "Processor Type\tCore Ultra 7\nMaximum Turbo Speed\t4.80 GHz"]], [], [$page]);
    }

    public function test_known_url_without_captured_text_is_not_proof(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');
        (new ProductSpecificationEvidenceGuard)->validate([], [[...$this->fact(), 'evidence' => 'confirmed']], [], [
            ['url' => 'https://maker.example/specs.pdf', 'specification_text' => ''],
        ]);
    }

    public function test_missing_page_feedback_distinguishes_absent_evidence_from_a_bad_quote(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No saved text is available for this URL');

        (new ProductSpecificationEvidenceGuard)->validate([], [[...$this->fact(), 'evidence' => 'confirmed']], [], []);
    }

    public function test_joined_sentences_are_rejected_with_repair_guidance_not_a_url_guess(): void
    {
        $url = 'https://shop.example/product';
        $first = 'The processor has 16 cores.';
        $last = 'Its maximum frequency is 4.8 GHz.';
        $page = ['url' => $url, 'specification_text' => $first.' A benchmark score is reported here. '.$last];
        $item = ['key' => 'cpu', 'value' => '16 cores, up to 4.8 GHz', 'evidence' => 'confirmed',
            'source_url' => $url, 'source_excerpt' => $first.' '.$last];
        $guard = new ProductSpecificationEvidenceGuard;

        try {
            $guard->validate([], [$item], [], [$page]);
            $this->fail('Nonadjacent sentences were accepted as a continuous quote');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Saved text exists for this URL', $exception->getMessage());
            $this->assertStringContainsString('separate nonadjacent passages with a newline or semicolon', $exception->getMessage());
            $this->assertStringContainsString('Do not change the source URL just to retry this quote', $exception->getMessage());
        }

        $guard->validate([], [[...$item, 'source_excerpt' => $first."\n".$last]], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_a_quote_from_another_url_cannot_be_attributed_to_this_one(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');
        (new ProductSpecificationEvidenceGuard)->validate([], [[...$this->fact(), 'evidence' => 'confirmed']], [], [
            ['url' => 'https://other.example/specs', 'specification_text' => 'Installed RAM: 64 GB'],
        ]);
    }

    public function test_spaces_entities_and_redirect_aliases_do_not_invalidate_a_real_excerpt(): void
    {
        (new ProductSpecificationEvidenceGuard)->validate([], [[...$this->fact(), 'evidence' => 'confirmed']], [], [
            ['url' => 'https://maker.example/old', 'final_url' => 'https://maker.example/specs.pdf',
                'specification_text' => 'Installed RAM:&nbsp; 64   GB'],
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_a_confirmed_label_cannot_silently_replace_an_existing_value(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confirmed but changed');
        $fact = $this->fact();
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'value' => '48 GB', 'evidence' => 'confirmed']], [], []);
    }

    public function test_the_confirmed_but_changed_message_names_both_values(): void
    {
        // Live #274: three repairs kept "confirmed" while the message never named the earlier value.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ram: confirmed but changed from "64 GB" to "48 GB"');
        $fact = $this->fact();
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'value' => '48 GB', 'evidence' => 'confirmed']], [], []);
    }

    public function test_a_trailing_decimal_zero_is_the_same_value(): void
    {
        // Live #274: "19.90 mm" against "19.9 mm" was "confirmed but changed" three times.
        $page = ['url' => 'https://shop.example/aspire', 'specification_text' => 'Dimensions: 362.3 × 237.4 × 19.90 mm'];
        $fact = ['key' => 'dimensions', 'name' => 'Dimensions', 'value' => '362.3 × 237.4 × 19.9 mm',
            'source_url' => 'https://maker.example/aspire', 'source_excerpt' => 'Dimensions: 362.3 × 237.4 × 19.9 mm'];
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => '362.3 × 237.4 × 19.90 mm',
            'source_url' => 'https://shop.example/aspire', 'source_excerpt' => 'Dimensions: 362.3 × 237.4 × 19.90 mm'];

        (new ProductSpecificationEvidenceGuard)->validate([$fact], [$candidate], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_a_whole_number_written_with_a_zero_decimal_is_the_same_value(): void
    {
        // Live #318: "14.0 inches" against "14 inches" was "confirmed but changed" three
        // times, and the card went out unreconciled over a screen size nobody disputed.
        $page = ['url' => 'https://shop.example/tecra', 'specification_text' => 'Screen size: 14 inches'];
        $fact = ['key' => 'screen_size', 'name' => 'Screen size', 'value' => '14.0 inches',
            'source_url' => 'https://maker.example/tecra', 'source_excerpt' => 'Display: 14.0 inches'];
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => '14 inches',
            'source_url' => 'https://shop.example/tecra', 'source_excerpt' => 'Screen size: 14 inches'];

        (new ProductSpecificationEvidenceGuard)->validate([$fact], [$candidate], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_a_reformatted_number_labelled_corrected_is_not_unchanged(): void
    {
        // The other label for the #274 case: "19.9" to "19.90" is the same value, so
        // "corrected" is no lie and needs no review of the source that said "19.9".
        $page = ['url' => 'https://shop.example/aspire', 'specification_text' => 'Dimensions: 362.3 × 237.4 × 19.90 mm'];
        $fact = ['key' => 'dimensions', 'name' => 'Dimensions', 'value' => '362.3 × 237.4 × 19.9 mm',
            'source_url' => 'https://maker.example/aspire', 'source_excerpt' => 'Dimensions: 362.3 × 237.4 × 19.9 mm'];
        $candidate = [...$fact, 'evidence' => 'corrected', 'value' => '362.3 × 237.4 × 19.90 mm', 'reason' => 'The selected listing gives 19.90 mm.',
            'source_url' => 'https://shop.example/aspire', 'source_excerpt' => 'Dimensions: 362.3 × 237.4 × 19.90 mm'];

        (new ProductSpecificationEvidenceGuard)->validate([$fact], [$candidate], [], [$page]);
        $this->addToAssertionCount(1);
    }

    public function test_a_thousands_group_is_not_a_trailing_zero(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confirmed but changed');
        $fact = $this->fact(['key' => 'weight', 'name' => 'Weight', 'value' => '1.25 kg', 'source_excerpt' => 'Weight: 1.25 kg']);
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'value' => '1.250 kg', 'evidence' => 'confirmed']], [], []);
    }

    public function test_a_value_capitalised_at_its_start_is_held_by_its_lower_case_quote(): void
    {
        // Live #273: "Up to 4.9 GHz" quoted as "up to 4.9 GHz" stayed "confirmed but changed".
        $fact = ['key' => 'cpu_max_frequency', 'name' => 'Max frequency', 'value' => '4.9 GHz',
            'source_url' => 'https://maker.example/elitebook', 'source_excerpt' => 'Max turbo: 4.9 GHz'];
        $listing = 'https://shop.example/elitebook';
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => 'Up to 4.9 GHz', 'source_url' => $listing,
            'source_excerpt' => 'Intel Core Ultra 5 125U (up to 4.9 GHz)', 'considered_source_urls' => ['https://maker.example/elitebook'],
            'reason' => 'The selected listing states the boost frequency.'];
        $pages = [['url' => $listing, 'specification_text' => 'Processor: Intel Core Ultra 5 125U (up to 4.9 GHz)']];

        $result = (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], $pages);

        $this->assertSame('corrected', $result[0]['evidence']);
    }

    public function test_only_the_first_letter_of_a_value_is_case_free_against_its_quote(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('confirmed but changed');
        $fact = ['key' => 'cpu_max_frequency', 'name' => 'Max frequency', 'value' => '4.9 GHz',
            'source_url' => 'https://maker.example/elitebook', 'source_excerpt' => 'Max turbo: 4.9 GHz'];
        $listing = 'https://shop.example/elitebook';
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => 'Up To 4.9 GHZ', 'source_url' => $listing,
            'source_excerpt' => 'Intel Core Ultra 5 125U (up to 4.9 GHz)', 'considered_source_urls' => ['https://maker.example/elitebook'],
            'reason' => 'The selected listing states the boost frequency.'];

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], [
            ['url' => $listing, 'specification_text' => 'Processor: Intel Core Ultra 5 125U (up to 4.9 GHz)'],
        ]);
    }

    public function test_unchanged_cited_value_only_repairs_the_status_after_all_conflicts_are_accounted_for(): void
    {
        $fact = $this->fact(['value' => '256 GB', 'source_excerpt' => 'Max. Memory: 256 GB']);
        $other = $this->fact(['value' => '192 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Max. Memory: 192 GB']);
        $candidate = [...$fact, 'evidence' => 'corrected', 'reason' => 'The retailer observation is outdated for this model.',
            'considered_source_urls' => [$other['source_url']]];

        $result = (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [$other], []);

        $this->assertSame([[...$candidate, 'evidence' => 'confirmed']], $result);
        $this->assertSame('corrected', $candidate['evidence']);
    }

    public function test_the_same_value_quoted_from_a_saved_page_is_confirmed_not_rejected_as_unchanged(): void
    {
        // Live #450: research's RAM type stayed, re-cited from the Icecat datasheet.
        $fact = $this->fact(['key' => 'ram_type', 'name' => 'RAM type', 'value' => 'LPDDR5x',
            'source_url' => 'https://shop.example/xps', 'source_excerpt' => 'Paměť: 32 GB LPDDR5x']);
        $datasheet = 'https://icecat.biz/p/dell/xps9440/xps-14-1.html';
        $candidate = [...$fact, 'evidence' => 'corrected', 'source_url' => $datasheet,
            'source_excerpt' => '32 GB LPDDR5x-SDRAM 7467 MHz', 'reason' => 'The datasheet is more specific than the shop.',
            'considered_source_urls' => ['https://shop.example/xps']];
        $pages = [['url' => $datasheet, 'specification_text' => "Memory · Internal memory: 32 GB LPDDR5x-SDRAM 7467 MHz\nMemory · Slots: none"]];

        $result = (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], $pages);

        $this->assertSame('confirmed', $result[0]['evidence']);

        // The page still has to hold the quote.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');
        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [[...$candidate, 'source_excerpt' => 'Official: LPDDR5x']], [], $pages);
    }

    public function test_a_changed_value_with_its_reason_and_quote_is_a_correction_even_when_labelled_confirmed(): void
    {
        // Live #451: the exact listing's part number replaced research's, labelled "confirmed".
        $fact = $this->fact(['key' => 'mpn', 'name' => 'MPN', 'value' => '21KC0063US',
            'source_url' => 'https://shop.example/x1', 'source_excerpt' => 'Part: 21KC0063US']);
        $listing = 'https://other.example/x1-21kdsn2000';
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => '21KDSN2000', 'source_url' => $listing,
            'source_excerpt' => 'Model 21KDSN2000', 'reason' => 'The exact-configuration listing names this part number.',
            'considered_source_urls' => ['https://shop.example/x1']];
        $pages = [['url' => $listing, 'specification_text' => 'Lenovo ThinkPad X1 Carbon Gen 12 · Model 21KDSN2000 · 32 GB']];

        $result = (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], $pages);

        $this->assertSame('corrected', $result[0]['evidence']);
    }

    public function test_a_changed_value_held_by_its_quote_is_a_correction_whose_reason_is_the_quote_when_none_was_written(): void
    {
        // Live #168: "wireless: confirmed but changed" failed the card twice over the
        // label alone, while the cited quote held the new value all along.
        $fact = $this->fact(['key' => 'wireless', 'name' => 'Wireless', 'value' => 'Wi-Fi 6',
            'source_url' => 'https://shop.example/x13', 'source_excerpt' => 'Wireless: Wi-Fi 6']);
        $datasheet = 'https://icecat.biz/p/lenovo/21lu0066us/thinkpad-x13-gen-5-1.html';
        $candidate = [...$fact, 'evidence' => 'confirmed', 'value' => 'Wi-Fi 6E', 'source_url' => $datasheet,
            'source_excerpt' => 'Wi-Fi standard: Wi-Fi 6E (802.11ax)', 'considered_source_urls' => ['https://shop.example/x13']];
        unset($candidate['reason']);
        $pages = [['url' => $datasheet, 'specification_text' => 'Networking · Wi-Fi standard: Wi-Fi 6E (802.11ax) · Bluetooth 5.3']];

        $result = (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], $pages);

        $this->assertSame('corrected', $result[0]['evidence']);
        $this->assertStringStartsWith('Значение исправлено по цитате выбранного источника: «Wi-Fi standard: Wi-Fi 6E', $result[0]['reason']);
    }

    #[DataProvider('unsafeStatusRepairs')]
    public function test_status_repair_never_bypasses_evidence_or_actual_value_checks(array $changes, string $error): void
    {
        $fact = $this->fact(['value' => '256 GB', 'source_excerpt' => 'Max. Memory: 256 GB']);
        $candidate = [...$fact, 'evidence' => 'corrected', 'reason' => 'Retained the observed capacity.', ...$changes];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($error);

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [$candidate], [], []);
    }

    public static function unsafeStatusRepairs(): array
    {
        return [
            'fabricated quote still contains value' => [['source_excerpt' => 'Official verified maximum: 256 GB'], 'excerpt was not observed'],
            'quote lacks value' => [['source_excerpt' => 'Max. Memory'], 'corrected but unchanged'],
            'no reason' => [['reason' => ''], 'corrected value requires a reason'],
            'separate unit changed' => [['unit' => 'MB'], 'corrected but unchanged'],
            'unit case is not equivalent' => [['value' => '256 Gb', 'source_excerpt' => 'Max. Memory: 256 Gb'], 'corrected but unchanged'],
            'confirmed value changed' => [['value' => '192 GB', 'evidence' => 'confirmed'], 'confirmed but changed'],
        ];
    }

    public function test_normalized_status_still_requires_review_of_the_other_cited_source(): void
    {
        $fact = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing source URLs: https://shop.example/product.');

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [[...$fact, 'evidence' => 'corrected',
            'reason' => 'The original value is retained.']], [$other], []);
    }

    public function test_a_reason_that_names_the_other_source_or_its_value_counts_as_its_review(): void
    {
        // 79 of 124 rejected assemblies in 30 days had a written reason and only a short URL list.
        $fact = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);
        $guard = new ProductSpecificationEvidenceGuard;

        foreach (['shop.example lists 48 GB for a different bundle; the maker page is kept.',
            'The other offer says 48 GB, which is the upgraded bundle; the original value is retained.'] as $reason) {
            $card = $guard->normalizeAndValidate([$fact], [[...$fact, 'evidence' => 'corrected', 'reason' => $reason]], [$other], []);
            $this->assertContains('https://shop.example/product', $card[0]['considered_source_urls']);
        }
    }

    public function test_a_value_is_named_only_as_a_whole_word(): void
    {
        $fact = $this->fact(['key' => 'webcam_present', 'name' => 'Webcam', 'value' => 'Yes', 'source_excerpt' => 'Webcam: Yes']);
        $other = $this->fact(['key' => 'webcam_present', 'name' => 'Webcam', 'value' => 'Non',
            'source_url' => 'https://shop.example/product', 'source_excerpt' => 'Webcam: Non']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing source URLs: https://shop.example/product.');

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [[...$fact, 'evidence' => 'corrected',
            'reason' => 'Nonetheless the maker page is kept.']], [$other], []);
    }

    public function test_even_an_old_cited_value_needs_its_full_literal_value_in_the_quote_for_status_repair(): void
    {
        $fact = $this->fact(['value' => 'Wi-Fi 6E', 'source_excerpt' => 'Wireless: Wi-Fi']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('corrected but unchanged');

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$fact], [[...$fact, 'evidence' => 'corrected',
            'reason' => '6E is not documented, removing the unsupported detail.']], [], []);
    }

    public function test_an_operator_typed_code_can_be_carried_with_operator_provenance_but_not_invented(): void
    {
        $request = 'Alienware 16X Aurora; AC16251; AWAC16251-9765BLU-PDE. Blue. 32GB memory; 1TB SSD';
        $sku = ['key' => 'sku', 'name' => 'SKU', 'value' => 'AWAC16251-9765BLU-PDE', 'evidence' => 'added',
            'source_kind' => 'operator_request', 'source_url' => null, 'source_ref' => null,
            'source_excerpt' => 'AWAC16251-9765BLU-PDE', 'reason' => 'Operator label code; no observed page repeats it.'];
        $guard = new ProductSpecificationEvidenceGuard;

        $this->assertSame('AWAC16251-9765BLU-PDE', $guard->normalizeAndValidate([], [$sku], [], [], [], $request)[0]['value']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operator_request excerpt must be copied verbatim');
        $guard->normalizeAndValidate([], [[...$sku, 'value' => 'AWAC16251-1111RED-PDE',
            'source_excerpt' => 'AWAC16251-1111RED-PDE']], [], [], [], $request);
    }

    public function test_status_repair_cannot_promote_uncertain_label_provenance(): void
    {
        $label = ['key' => 'ram', 'value' => '64 GB', 'source_kind' => 'photo_label', 'source_ref' => 'photo:1',
            'source_excerpt' => 'RAM: 64 GB', 'confidence' => 0.4, 'uncertain_characters' => ['6']];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('photo_label excerpt was not observed');

        (new ProductSpecificationEvidenceGuard)->normalizeAndValidate([$label], [[...$label, 'evidence' => 'corrected',
            'reason' => 'The label capacity is retained.']], [], [], [$label]);
    }

    public function test_literal_value_must_not_be_cut_out_of_a_larger_number_or_unit(): void
    {
        $guard = new ProductSpecificationEvidenceGuard;
        foreach (['Max. Memory: 1256 GB', 'Max. Memory: 256 GBps'] as $quote) {
            $fact = $this->fact(['value' => '256 GB', 'source_excerpt' => $quote]);
            try {
                $guard->normalizeAndValidate([$fact], [[...$fact, 'evidence' => 'corrected', 'reason' => 'Retained.']], [], []);
                $this->fail('A partial number or unit authorized metadata repair.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('corrected but unchanged', $error->getMessage());
            }
        }
    }

    public function test_old_supported_facts_cannot_be_dropped_without_accounting_for_their_sources(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('considered_source_urls');
        $fact = $this->fact();
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'evidence' => 'dropped',
            'reason' => 'The photo page does not mention RAM.']], [], []);
    }

    public function test_agent_can_drop_an_inapplicable_fact_after_reviewing_its_evidence(): void
    {
        $fact = $this->fact();
        (new ProductSpecificationEvidenceGuard)->validate([$fact], [[...$fact, 'evidence' => 'dropped',
            'reason' => 'This specification applies only to another regional revision.',
            'considered_source_urls' => [$fact['source_url']]]], [], []);
        $this->addToAssertionCount(1);
    }

    public function test_keeping_the_old_value_still_requires_addressing_a_new_differing_observation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('considered_source_urls');
        $old = $this->fact();
        $new = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);
        (new ProductSpecificationEvidenceGuard)->validate([$old], [[...$old, 'evidence' => 'confirmed']], [$new], []);
    }

    public function test_equivalent_spellings_can_be_resolved_by_the_agent_without_a_numeric_dictionary(): void
    {
        $old = $this->fact();
        $new = $this->fact(['value' => '64GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 64GB']);
        (new ProductSpecificationEvidenceGuard)->validate([$old], [[...$old, 'evidence' => 'confirmed',
            'reason' => 'Both sources describe the same capacity; only spacing differs.',
            'considered_source_urls' => [$old['source_url'], $new['source_url']]]], [$new], []);
        $this->addToAssertionCount(1);
    }

    public function test_differing_observations_keep_both_sides_for_the_agent_without_choosing_a_winner(): void
    {
        $one = $this->fact();
        $two = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);
        $this->assertSame(['ram' => [$one, $two]], (new ProductSpecificationEvidenceGuard)->differingObservations([$one, $two]));
    }

    public function test_selected_cited_source_does_not_need_to_be_duplicated_in_considered_urls(): void
    {
        $selected = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);

        (new ProductSpecificationEvidenceGuard)->validate([$selected], [[...$selected, 'evidence' => 'confirmed',
            'reason' => 'The shop observation describes a different regional revision.',
            'considered_source_urls' => [$other['source_url']]]], [$other], []);

        $this->addToAssertionCount(1);
    }

    public function test_selected_source_and_reason_do_not_replace_review_of_the_other_source(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing source URLs: https://shop.example/product.');
        $selected = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);

        (new ProductSpecificationEvidenceGuard)->validate([$selected], [[...$selected, 'evidence' => 'confirmed',
            'reason' => 'This source appears applicable.', 'considered_source_urls' => []]], [$other], []);
    }

    public function test_all_considered_sources_still_require_an_explanation_of_the_difference(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing explanation in reason.');
        $selected = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);

        (new ProductSpecificationEvidenceGuard)->validate([$selected], [[...$selected, 'evidence' => 'confirmed',
            'reason' => '', 'considered_source_urls' => [$selected['source_url'], $other['source_url']]]], [$other], []);
    }

    public function test_implicitly_considered_source_still_requires_its_real_quote(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excerpt was not observed');
        $selected = $this->fact();
        $other = $this->fact(['value' => '48 GB', 'source_url' => 'https://shop.example/product', 'source_excerpt' => 'RAM: 48 GB']);

        (new ProductSpecificationEvidenceGuard)->validate([$selected], [[...$selected, 'evidence' => 'confirmed',
            'source_excerpt' => 'Confirmed factory-installed RAM: 64 GB',
            'reason' => 'The shop observation describes a different regional revision.',
            'considered_source_urls' => [$other['source_url']]]], [$other], []);
    }
}
