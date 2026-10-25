<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductPhotoApplicabilityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductSourceIdentityGate;
use App\Services\Products\SourceIdentityProvenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordIdentityGateTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.42/p/samsung/mz-v9p2t0bw/990-pro-1.html';

    protected function setUp(): void
    {
        parent::setUp();
        ProductPhotoApplicabilityAgent::fake()->preventStrayPrompts();
    }

    public function test_a_record_matched_by_code_is_the_exact_product_whatever_its_page_shows(): void
    {
        // Live #157: the record's page answered with a bot check, the photo judge said
        // "uncertain" and eight official frames were set aside and lost.
        $draft = $this->draft();
        $gate = app(ProductSourceIdentityGate::class);
        $source = $this->source(_preflight_identity_evidence: "Making sure you're not a bot!");

        $photos = $gate->assessPhotos($draft, $source, $draft->telegram_update_id);

        $this->assertSame('exact', $photos['verdict']);
        $this->assertTrue($photos['may_inspect_gallery']);
        $this->assertStringContainsString('MZ-V9P2T0BW', implode(' ', $photos['evidence']));
        $this->assertSame('confirmed', $gate->assess($draft, $source, $draft->telegram_update_id));
        $this->assertFalse($gate->knownConflict($draft, $source, $draft->telegram_update_id));
        ProductPhotoApplicabilityAgent::assertNeverPrompted();
    }

    public function test_a_conflict_the_browser_observed_on_the_record_page_still_wins(): void
    {
        $draft = $this->draft();
        $gate = app(ProductSourceIdentityGate::class);
        $source = $this->source(_observed_identity: ['purpose' => 'photos', 'verdict' => 'conflicting',
            'product_draft_id' => $draft->id, 'telegram_update_id' => $draft->telegram_update_id, 'source' => ['url' => self::PAGE]]);

        $this->assertSame('conflicting', $gate->assessPhotos($draft, $source, $draft->telegram_update_id)['verdict']);
        $this->assertSame('conflicting', $gate->assess($draft, $source, $draft->telegram_update_id));
        ProductPhotoApplicabilityAgent::assertNeverPrompted();
    }

    private function source(mixed ...$overrides): array
    {
        return ['url' => self::PAGE, 'title' => 'Icecat datasheet: Samsung 990 PRO 2 TB M.2 PCI Express 4.0 NVMe', 'type' => 'web',
            'roles' => ['facts', 'exact_photo'], '_official_gallery' => true,
            'image_urls' => ['https://93.184.216.42/img/gallery/a.jpg'],
            '_preflight_identity_evidence' => 'Samsung 990 PRO 2 TB M.2 PCI Express 4.0 NVMe Part number: MZ-V9P2T0BW GTIN: 8806094215038',
            SourceIdentityProvenance::KEY => SourceIdentityProvenance::codeMatch('icecat', ['MZ-V9P2T0BW', '8806094215038']),
            ...$overrides];
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 981235, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => 'Samsung 990 PRO 2TB MZ-V9P2T0BW']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'research', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'Samsung 990 PRO 2TB NVMe M.2 SSD', 'model' => '990 PRO 2TB MZ-V9P2T0BW',
            'brand' => 'Samsung', 'color' => 'Black', 'category' => 'laptops', 'sources' => [], 'image_urls' => [],
            'specifications' => [['key' => 'sku', 'value' => 'MZ-V9P2T0BW']]]);
    }
}
