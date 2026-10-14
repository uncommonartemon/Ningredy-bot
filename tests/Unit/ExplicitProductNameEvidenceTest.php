<?php

namespace Tests\Unit;

use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductIdentityMatcher;
use App\Services\Products\ProductSourceIdentityGate;
use App\Services\Products\ProductSourceIdentityJudge;
use Mockery;
use Tests\TestCase;

class ExplicitProductNameEvidenceTest extends TestCase
{
    public function test_an_exact_code_and_reordered_descriptive_name_need_no_paid_identity_judge(): void
    {
        $draft = $this->draft();
        $source = $this->source('Acme 14 inch Orbit Pro Q14-512EU');
        $matcher = new ProductIdentityMatcher;

        $this->assertTrue($matcher->confirmsExactIdentifier($draft, $source));
        $this->assertSame([], $matcher->missingOperatorIdentifiers($draft, $source['title']));
        $this->assertFalse($matcher->conflictsSource($draft, $source));

        $judge = Mockery::mock(ProductSourceIdentityJudge::class);
        $judge->shouldNotReceive('judge');
        $this->assertSame('confirmed', (new ProductSourceIdentityGate($matcher, $judge))->assess($draft, $source, 123));
    }

    public function test_reordering_never_erases_missing_words_or_changes_the_explicit_code(): void
    {
        $matcher = new ProductIdentityMatcher;
        foreach ([
            'Acme 14 inch Orbit Q14-512EU', // Missing Pro, not a synonym guessed by the server.
            'Acme 16 inch Orbit Pro Q14-512EU',
            'Acme 14 inch Orbit Pro Q14-512US',
            'Acme 14 inch Orbit Pro Q14-512EUX',
            'Acme 14 inch Orbit Pro Q14, configuration 512EU', // Split code is not the whole SKU.
        ] as $title) {
            $this->assertFalse($matcher->confirmsExactIdentifier($this->draft(), $this->source($title)), $title);
            $this->assertNotEmpty($matcher->missingOperatorIdentifiers($this->draft(), $title), $title);
        }
    }

    public function test_a_second_explicit_code_and_sibling_variants_still_require_resolution(): void
    {
        $draft = $this->draft();
        $draft->specifications = [
            ...$draft->specifications,
            ['key' => 'sku', 'value' => 'SHOP-7788EU'],
        ];
        $draft->telegramUpdate->text .= ' SHOP-7788EU';
        $matcher = new ProductIdentityMatcher;
        $title = 'Acme 14 inch Orbit Pro Q14-512EU';

        $this->assertFalse($matcher->confirmsExactIdentifier($draft, $this->source($title)));
        $this->assertContains('shop7788eu', $matcher->missingOperatorIdentifiers($draft, $title));
        $this->assertTrue($matcher->confirmsExactIdentifier($draft, $this->source($title.' SKU SHOP-7788EU')));
        // A one-character sibling is inside the existing conservative
        // ambiguity detector; this patch does not widen that detector.
        $this->assertFalse($matcher->confirmsExactIdentifier($this->draft(), $this->source($title.' or Q14-512EX')));
    }

    public function test_a_dedicated_identifier_itself_is_never_split_or_reordered(): void
    {
        $draft = $this->draft();
        $draft->model = 'Orbit 14 Pro';
        $draft->specifications = [['key' => 'mpn', 'value' => 'Q14 PRO512']];
        $draft->telegramUpdate->text = 'Orbit 14 Pro Q14 PRO512';

        $this->assertFalse((new ProductIdentityMatcher)->confirmsExactIdentifier(
            $draft, $this->source('Acme Orbit 14 Pro PRO512 Q14'),
        ));
    }

    public function test_free_text_without_a_separate_exact_code_does_not_gain_whole_gallery_trust(): void
    {
        $draft = $this->draft();
        $draft->specifications = [];

        $this->assertFalse((new ProductIdentityMatcher)->confirmsExactIdentifier(
            $draft, $this->source('Acme 14 inch Orbit Pro Q14-512EU'),
        ));
    }

    private function draft(): ProductDraft
    {
        $draft = new ProductDraft([
            'brand' => 'Acme',
            'model' => 'Orbit Pro 14 Q14-512EU',
            'specifications' => [['key' => 'mpn', 'value' => 'Q14-512EU']],
        ]);
        $draft->setRelation('telegramUpdate', new TelegramUpdate([
            'text' => 'Acme Orbit Pro 14 Q14-512EU новый',
        ]));

        return $draft;
    }

    private function source(string $title): array
    {
        return ['url' => 'https://shop.example/product/1', 'title' => $title];
    }
}
