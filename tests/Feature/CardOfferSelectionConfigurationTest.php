<?php

namespace Tests\Feature;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductPhotoApplicability;
use App\Services\Products\ProductSpecificationEvidenceGuard;
use App\Services\ProductSearch\Recovery\CardOfferSelection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardOfferSelectionConfigurationTest extends TestCase
{
    private const PHOTO_PAGE = 'https://shop.example/other-configuration';

    #[DataProvider('foreignConfigurationFacts')]
    public function test_compatible_photo_offer_cannot_supply_changed_or_new_configuration_facts(string $key, ?string $original, string $foreign): void
    {
        [$draft, $source, $card] = $this->fixture($key, $original, $foreign, self::PHOTO_PAGE);
        $pages = [['url' => self::PHOTO_PAGE, 'specification_text' => $key.': '.$foreign]];
        // The quote is real: provenance alone cannot authorize this other configuration.
        app(ProductSpecificationEvidenceGuard::class)->validate($draft->specifications, $card['specifications'], [], $pages);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('compatible gallery cannot supply');
        app(CardOfferSelection::class)->validate($draft, $source, $card, $pages, null);
    }

    public static function foreignConfigurationFacts(): array
    {
        return [
            'installed RAM' => ['ram', '32 GB', '16 GB'],
            'installed storage' => ['storage', '1 TB SSD', '512 GB SSD'],
            'processor' => ['cpu', 'Intel Core i7-13620H', 'Intel Core i5-13420H'],
            'new graphics field' => ['gpu', null, 'NVIDIA RTX 4050'],
        ];
    }

    public function test_observed_redirect_to_the_photo_offer_is_not_independent_text_evidence(): void
    {
        $alias = 'https://shop.example/old-url';
        [$draft, $source, $card] = $this->fixture('ram', '32 GB', '16 GB', $alias);
        $pages = [['url' => $alias, 'final_url' => self::PHOTO_PAGE, 'specification_text' => 'ram: 16 GB']];
        app(ProductSpecificationEvidenceGuard::class)->validate($draft->specifications, $card['specifications'], [], $pages);

        $this->expectException(ValidationException::class);
        app(CardOfferSelection::class)->validate($draft, $source, $card, $pages, null);
    }

    public function test_same_numeric_value_with_a_changed_unit_is_not_an_unchanged_fact(): void
    {
        [$draft, $source, $card] = $this->fixture('ram', '32', '32', self::PHOTO_PAGE);
        $draft->specifications = [[...$draft->specifications[0], 'unit' => 'GB']];
        $card['specifications'][0]['unit'] = 'TB';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('compatible gallery cannot supply');
        app(CardOfferSelection::class)->validate($draft, $source, $card, [], null);
    }

    public function test_separate_observed_target_configuration_can_correct_provisional_facts(): void
    {
        $textSource = 'https://manufacturer.example/TARGET-32/specifications';
        [$draft, $source, $card] = $this->fixture('ram', '16 GB', '32 GB', $textSource);
        $pages = [['url' => $textSource, 'identity_evidence' => 'Laptop TARGET-32', 'specification_text' => 'ram: 32 GB']];
        app(ProductSpecificationEvidenceGuard::class)->validate($draft->specifications, $card['specifications'], [], $pages);

        $result = app(CardOfferSelection::class)->validate($draft, $source, $card, $pages, null);

        $this->assertSame('retain_selected_offer', $result['action']);
        $this->assertSame('16 GB', $draft->specifications[0]['value'], 'Validation does not mutate the target.');
    }

    public function test_clear_factory_label_can_supply_the_selected_configuration(): void
    {
        [$draft, $source, $card] = $this->fixture('ram', '16 GB', '32 GB', '');
        $card['specifications'][0] += ['source_kind' => 'photo_label', 'source_ref' => 'telegram-photo:1:1'];
        $label = [...$card['specifications'][0], 'confidence' => 0.99, 'uncertain_characters' => null];
        app(ProductSpecificationEvidenceGuard::class)->validate($draft->specifications, $card['specifications'], [], [], [$label]);

        $result = app(CardOfferSelection::class)->validate($draft, $source, $card, [], null);

        $this->assertSame('retain_selected_offer', $result['action']);
    }

    public function test_shared_unchanged_specifications_are_not_blocked_by_photo_compatibility(): void
    {
        [$draft, $source, $card] = $this->fixture('ports', '2 USB-A, 1 HDMI', '2 USB-A, 1 HDMI', self::PHOTO_PAGE);
        $pages = [['url' => self::PHOTO_PAGE, 'specification_text' => 'ports: 2 USB-A, 1 HDMI']];
        app(ProductSpecificationEvidenceGuard::class)->validate($draft->specifications, $card['specifications'], [], $pages);

        $result = app(CardOfferSelection::class)->validate($draft, $source, $card, $pages, null);

        $this->assertSame('retain_selected_offer', $result['action']);
    }

    private function fixture(string $key, ?string $original, string $proposed, string $citation): array
    {
        $draft = new ProductDraft(['model' => 'TARGET-32', 'title' => 'Laptop TARGET-32', 'color' => 'black',
            'specifications' => $original === null ? [] : [['key' => $key, 'name' => $key, 'value' => $original]]]);
        $draft->id = 1;
        $draft->setRelation('telegramUpdate', new TelegramUpdate(['text' => 'Laptop TARGET-32, 32 GB RAM', 'payload' => []]));
        $source = ['url' => self::PHOTO_PAGE, 'title' => 'Laptop OTHER-16 black'];
        $source['_photo_applicability'] = ProductPhotoApplicability::bind($draft, $source, [
            'verdict' => 'compatible', 'reason' => 'Same exterior; different internal configuration.',
            'source_model' => 'OTHER-16', 'differences' => ['Different installed components.'],
        ]);
        $card = ['model' => $draft->model, 'color' => $draft->color,
            'offer_selection' => ['action' => 'retain_selected_offer', 'source_url' => self::PHOTO_PAGE],
            'specifications' => [['key' => $key, 'name' => $key, 'value' => $proposed,
                'evidence' => $original !== null && $original !== $proposed ? 'corrected' : 'confirmed',
                'source_url' => $citation, 'source_excerpt' => $key.': '.$proposed,
                'reason' => 'The cited page or label gives the proposed value.']]];

        return [$draft, $source, $card];
    }
}
