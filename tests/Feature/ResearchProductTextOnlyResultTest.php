<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResearchProductTextOnlyResultTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://shop.example/lenovo-exact-123';

    private function research(string $stopReason, bool $assembled, bool $moneyRemains = false, bool $moneyExhausted = false, int $photoCount = 0, string $verificationStatus = 'verified', bool $filesExist = true, string $sourceType = 'retailer'): array
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Queue::fake();
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'Lenovo Exact 123', 'brand' => 'Lenovo', 'model' => 'Exact 123',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => 'Black', 'condition' => 'new',
            'description' => 'Initial research description.', 'research_notes' => null,
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB'],
                // Live #413 already had exact label identifiers; source kind was its only blocker.
                ...($sourceType === 'web' ? [['key' => 'mpn', 'name' => 'Part number', 'value' => 'EXACT-123']] : [])],
            'sources' => [['title' => 'Exact laptop', 'url' => self::SOURCE, 'type' => $sourceType, 'image_urls' => []]],
            'primary_source_url' => self::SOURCE, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.95,
        ]])->preventStrayPrompts();
        $update = TelegramUpdate::query()->create(['update_id' => 1234567, 'telegram_user_id' => '123', 'chat_id' => '456',
            'message_id' => 10, 'text' => 'Find Lenovo Exact 123', 'payload' => [], 'status' => 'processing']);
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft) use ($stopReason, $assembled, $photoCount, $verificationStatus, $filesExist): int {
            $draft->update(['images_staged_at' => now(), 'gallery_status' => $photoCount > 0 ? 'partial' : 'missing', 'gallery_search_stop_reason' => $stopReason,
                ...($assembled ? [
                    'title' => 'Lenovo Exact 123 verified', 'description' => 'Final assembled description.',
                    'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB']],
                    'specifications_reconciled_source_url' => $draft->primary_source_url,
                ] : []),
            ]);

            for ($index = 0; $index < $photoCount; $index++) {
                $path = "drafts/{$draft->id}/{$index}.webp";
                if ($filesExist) {
                    Storage::disk('public')->put($path, 'previously-verified-photo-'.$index);
                }
                $draft->media()->create([
                    'disk' => 'public', 'path' => $path, 'source_url' => self::SOURCE."/{$index}.webp",
                    'mime_type' => 'image/webp', 'width' => 1600, 'height' => 1200,
                    'checksum' => hash('sha256', $path), 'verification_status' => $verificationStatus,
                    'sort_order' => $index, 'is_primary' => $index === 0,
                ]);
            }

            return $photoCount;
        });
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn($moneyRemains);
        $costBudget->shouldReceive('exceeded')->andReturn($moneyExhausted);

        return json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request(['query' => $update->text])), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function terminalBudgetStops(): array
    {
        return [['cost_budget'], ['time_budget']];
    }

    public function test_unclassified_web_html_candidate_reaches_the_gallery_stage_without_relabel_or_retry(): void
    {
        $result = $this->research('cost_budget', assembled: false, sourceType: 'web');
        $draft = ProductDraft::query()->sole();

        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertSame(self::SOURCE, $draft->primary_source_url);
        $this->assertSame('web', $draft->sources[0]['type']);
        $this->assertSame(1, AiRun::query()->count());
        $this->assertDatabaseMissing('product_source_attempts', ['phase' => 'research_recovery']);
    }

    #[DataProvider('terminalBudgetStops')]
    public function test_finished_text_card_survives_a_zero_photo_budget_stop(string $reason): void
    {
        $result = $this->research($reason, assembled: true);
        $draft = ProductDraft::query()->sole();
        $this->assertSame('found', $result['status']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertTrue($result['text_only_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame(0, $result['image_count']);
        $this->assertSame('Lenovo Exact 123 verified', $result['title']);
        $this->assertSame('Final assembled description.', $result['description']);
        $this->assertSame('32 GB', $result['specifications'][0]['value']);
        $this->assertSame('pending_review', $draft->status);
        $this->assertNull($draft->reviewed_at);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_a_time_slice_with_money_remaining_keeps_the_existing_continuation_policy(): void
    {
        $result = $this->research('time_budget', assembled: true, moneyRemains: true);
        $this->assertSame('gallery_search_continuing', $result['status']);
        $this->assertArrayNotHasKey('text_only_result', $result);
        $this->assertSame('pending_review', ProductDraft::query()->sole()->status);
    }

    public function test_source_exhaustion_keeps_searching_instead_of_prematurely_delivering_the_text_card(): void
    {
        $result = $this->research('exhausted', assembled: true, moneyRemains: true);
        $this->assertSame('gallery_search_continuing', $result['status']);
        Queue::assertPushed(ContinueDraftGallerySearch::class);
    }

    public function test_final_assembly_spending_the_remaining_money_delivers_text_instead_of_queueing_another_cycle(): void
    {
        $result = $this->research('exhausted', assembled: true, moneyExhausted: true);
        $this->assertSame('found', $result['status']);
        $this->assertTrue($result['text_only_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame('cost_budget', ProductDraft::query()->sole()->gallery_search_stop_reason);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    #[DataProvider('terminalBudgetStops')]
    public function test_unassembled_information_is_never_reported_as_a_finished_text_card(string $reason): void
    {
        $result = $this->research($reason, assembled: false);
        $this->assertSame('gallery_search_paused', $result['status']);
        $this->assertArrayNotHasKey('text_only_result', $result);
        $this->assertTrue(ProductDraft::query()->sole()->reconciliationPending());
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
    }

    public function test_zero_photo_card_with_incomplete_assembly_stays_pending_without_approval(): void
    {
        $result = $this->research('specifications_unreconciled', assembled: false);
        $draft = ProductDraft::query()->sole();
        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame('specifications_unreconciled', $result['stop_reason']);
        $this->assertSame('pending_review', $draft->status);
        $this->assertNull($draft->reviewed_at);
        $this->assertSame('16 GB', $draft->specifications[0]['value']);
    }

    public function test_assistant_keeps_the_id_of_a_ready_text_card_without_offering_publication(): void
    {
        $instructions = (string) app(ServerAssistantAgent::class)->instructions();
        $this->assertStringContainsString('text_only_result=true', $instructions);
        $this->assertStringContainsString('ready_for_approval=false запрещает публикацию', $instructions);
    }

    public static function partialPhotoResults(): array
    {
        return [['cost_budget', 7], ['time_budget', 1], ['specifications_unreconciled', 3]];
    }

    #[DataProvider('partialPhotoResults')]
    public function test_unassembled_card_returns_saved_photos_as_partial_result_without_unverified_facts(string $reason, int $photoCount): void
    {
        $result = $this->research($reason, assembled: false, photoCount: $photoCount);
        $draft = ProductDraft::query()->sole();

        $this->assertSame('partial_result', $result['status']);
        $this->assertTrue($result['partial_photo_result']);
        $this->assertFalse($result['ready_for_approval']);
        $this->assertSame($draft->id, $result['draft_id']);
        $this->assertSame($photoCount, $result['image_count']);
        $this->assertSame($photoCount, $result['partial_photo_count']);
        $this->assertSame($reason, $result['stop_reason']);
        $this->assertArrayNotHasKey('description', $result);
        $this->assertArrayNotHasKey('specifications', $result);
        $this->assertTrue($draft->reconciliationPending());
        $this->assertSame('16 GB', $draft->specifications[0]['value']);
        Queue::assertNotPushed(ContinueDraftGallerySearch::class);
        ProductResearchAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Lenovo Exact 123'));
    }

    public function test_pending_photos_do_not_promise_a_verified_preview(): void
    {
        $result = $this->research('cost_budget', assembled: false, photoCount: 3, verificationStatus: 'pending');

        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertFalse($result['partial_photo_result']);
        $this->assertSame(0, $result['partial_photo_count']);
        $this->assertSame(3, $result['image_count']);
    }

    public function test_missing_photo_files_do_not_promise_a_preview(): void
    {
        $result = $this->research('cost_budget', assembled: false, photoCount: 3, filesExist: false);

        $this->assertSame('card_assembly_paused', $result['status']);
        $this->assertFalse($result['partial_photo_result']);
        $this->assertSame(0, $result['partial_photo_count']);
    }
}
