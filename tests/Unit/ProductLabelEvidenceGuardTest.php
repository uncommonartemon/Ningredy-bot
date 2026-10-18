<?php

namespace Tests\Unit;

use App\Services\Products\InvalidSpecificationEvidence;
use App\Services\Products\ProductSpecificationEvidenceGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductLabelEvidenceGuardTest extends TestCase
{
    private function label(array $changes = []): array
    {
        return [...[
            'key' => 'ram', 'value' => '16 GB', 'source_kind' => 'photo_label',
            'source_ref' => 'telegram-photo:395:1', 'source_excerpt' => 'Memory: 16 GB DDR4',
            'confidence' => 0.95, 'uncertain_characters' => [],
        ], ...$changes];
    }

    private function proposedFact(array $changes = []): array
    {
        return [...$this->label(), 'name' => 'Memory', 'evidence' => 'confirmed', 'source_url' => null, ...$changes];
    }

    public function test_a_clear_factory_label_supports_its_fact_without_any_website(): void
    {
        (new ProductSpecificationEvidenceGuard)->validate([], [$this->proposedFact()], [], [], [$this->label()]);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unreliableLabels')]
    public function test_an_unreliable_or_different_observation_is_not_promoted_to_a_label(array $changes): void
    {
        $this->expectException(InvalidSpecificationEvidence::class);
        $this->expectExceptionMessage('photo_label excerpt was not observed');
        (new ProductSpecificationEvidenceGuard)->validate([], [$this->proposedFact()], [], [], [$this->label($changes)]);
    }

    public static function unreliableLabels(): array
    {
        return [
            'handwritten' => [['source_kind' => 'handwritten']],
            'legacy OCR' => [['source_kind' => 'photo_transcription']],
            'unconfident' => [['confidence' => 0.6]],
            'unmeasured confidence' => [['confidence' => null]],
            'ambiguous character' => [['uncertain_characters' => ['6 or 8']]],
            'different photo' => [['source_ref' => 'telegram-photo:395:2']],
            'different number' => [['source_excerpt' => 'Memory: 8 GB DDR4']],
        ];
    }

    public function test_web_claims_cannot_be_laundered_as_a_label(): void
    {
        $this->expectException(InvalidSpecificationEvidence::class);
        (new ProductSpecificationEvidenceGuard)->validate([], [$this->proposedFact(['source_url' => 'https://shop.example/p'])], [], [], [$this->label()]);
    }

    public function test_normalizing_the_attribute_key_does_not_change_the_quote_provenance(): void
    {
        (new ProductSpecificationEvidenceGuard)->validate([], [$this->proposedFact()], [], [], [$this->label(['key' => 'ram_capacity'])]);
        $this->addToAssertionCount(1);
    }

    public function test_conflicting_label_and_web_values_need_both_sources_accounted_for(): void
    {
        $web = ['key' => 'ram', 'value' => '8 GB', 'source_url' => 'https://shop.example/p', 'source_excerpt' => 'Memory: 8 GB'];
        $guard = new ProductSpecificationEvidenceGuard;
        try {
            $guard->validate([], [$this->proposedFact()], [$web], [], [$this->label()]);
            $this->fail('A conflicting website was ignored');
        } catch (InvalidSpecificationEvidence $exception) {
            $this->assertStringContainsString('https://shop.example/p', $exception->getMessage());
        }

        $guard->validate([], [$this->proposedFact([
            'considered_source_urls' => ['https://shop.example/p'],
            'reason' => 'The factory label documents this physical item; the shop describes another memory configuration.',
        ])], [$web], [], [$this->label()]);
        $this->addToAssertionCount(1);
    }

    public function test_choosing_web_value_cannot_silently_ignore_a_different_label(): void
    {
        $web = ['key' => 'ram', 'value' => '8 GB', 'source_url' => 'https://shop.example/p', 'source_excerpt' => 'Memory: 8 GB'];
        $this->expectException(InvalidSpecificationEvidence::class);
        $this->expectExceptionMessage('Missing input evidence references');
        (new ProductSpecificationEvidenceGuard)->validate([], [[...$web, 'evidence' => 'confirmed', 'reason' => 'Chosen web offer.']], [$web], [], [$this->label()]);
    }
}
