<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductRequestContext;
use App\Services\ProductSearch\Recovery\CardOfferSelection;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramAssistantReplyDelivery;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Live #215: "Lenovo Legion 5 15IRX9 Luna Grey" - every page of that model and the
 * maker's sheet named Storm Grey, research answered "unresolved" three times, no
 * draft was made and the operator was asked to clarify. Research now returns the
 * closest observed configuration and names the difference; every later step and
 * the card read it from the request.
 */
class RequestDifferencesTest extends TestCase
{
    use RefreshDatabase;

    private const array COLOUR = ['requirement' => 'color', 'requested' => 'Luna Grey', 'found' => 'Storm Grey',
        'evidence' => 'Lenovo PSREF and every listing of Legion 5 15IRX9 say Storm Grey; Luna Grey is the LOQ 15IRX9.'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Sleep::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        $budget = $this->mock(ProductSearchTimeBudget::class);
        $budget->shouldReceive('canStart')->andReturn(true);
    }

    public function test_research_differences_reach_every_agent_until_a_new_answer_replaces_them(): void
    {
        [$update, $draft] = $this->draft(photoCount: 0);

        ProductRequestContext::rememberDifferences($update, ['request_differences' => [self::COLOUR], 'unconfirmed_operator_codes' => []]);
        $context = ProductRequestContext::forDraft($draft->fresh())->forAgent();

        $this->assertSame('Luna Grey', $context['request_differences']['items'][0]['requested']);
        $this->assertSame('Storm Grey', $context['request_differences']['items'][0]['found']);
        $this->assertStringContainsString('not by itself a reason to reject', $context['request_differences']['note']);
        // A retraining note narrows the request; it does not drop what research found.
        $hinted = ProductRequestContext::forDraft($draft->fresh())->withOperatorHint('only the lid')->forAgent();
        $this->assertSame('Luna Grey', $hinted['request_differences']['items'][0]['requested']);
        // The progress message id and other request keys survive the write.
        $this->assertSame(7206, $update->fresh()->payload['_progress_message_id']);

        ProductRequestContext::rememberDifferences($update, ['request_differences' => [], 'unconfirmed_operator_codes' => []]);

        $this->assertArrayNotHasKey('request_differences', ProductRequestContext::forDraft($draft->fresh())->forAgent());
    }

    public function test_research_notes_reach_every_agent_with_the_selected_product(): void
    {
        // Live #279: research knew "MSN 30035506 / MD 62518", the model said only "(30035506)",
        // and a shop selling the MD62518 chassis was held as another configuration.
        [, $draft] = $this->draft(photoCount: 0);
        $draft->update(['research_notes' => 'The manufacturer document identifies this configuration as MSN 30035506 / MD 62518.']);

        $context = ProductRequestContext::forDraft($draft->fresh())->forAgent();

        $this->assertStringContainsString('MD 62518', $context['selected_product']['research_notes']);

        $draft->update(['research_notes' => null]);
        $this->assertArrayNotHasKey('research_notes', ProductRequestContext::forDraft($draft->fresh())->forAgent()['selected_product']);
    }

    public function test_card_assembly_and_the_offer_choice_see_the_differences(): void
    {
        [$update, $draft] = $this->draft(photoCount: 0);
        ProductRequestContext::rememberDifferences($update, ['request_differences' => [self::COLOUR]]);

        $context = app(CardOfferSelection::class)->context($draft->fresh(), ['url' => 'https://shop.example/legion', 'title' => 'Legion 5']);

        $this->assertSame('Storm Grey', $context['request_differences']['items'][0]['found']);
    }

    public function test_the_card_names_the_difference_and_an_unobserved_code_beside_the_buttons(): void
    {
        [$update, $draft] = $this->draft(photoCount: 1);
        ProductRequestContext::rememberDifferences($update, ['request_differences' => [self::COLOUR], 'unconfirmed_operator_codes' => ['83DG00ABCD']]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));

        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '98765', $draft->fresh());

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '⚠️ Не совпадает с запросом. Цвет: в запросе «Luna Grey», найдено «Storm Grey».')
            && str_contains((string) $request['text'], 'Код из запроса не найден ни на одной странице: 83DG00ABCD'));
    }

    public function test_a_partial_result_names_the_difference_in_its_caption(): void
    {
        [$update, $draft] = $this->draft(photoCount: 1);
        ProductRequestContext::rememberDifferences($update, ['request_differences' => [self::COLOUR]]);
        $draft->update(['specifications_reconciled_source_url' => null, 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        $draft->media()->first()->update(['verification_status' => 'source_verified']);
        $this->mock(ProductSearchCostBudget::class)->shouldReceive('hasRemaining')->andReturn(false);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));
        $delivery = app(TelegramAssistantReplyDelivery::class);

        $delivery->deliver($delivery->prepareDraft($update->fresh(), $draft->fresh()), app(TelegramClient::class));

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/sendPhoto')) {
                return false;
            }
            $caption = (string) collect($request->data())->pluck('contents', 'name')->get('caption');

            return str_contains($caption, "Запрос: Lenovo Legion 5 15IRX9 Luna Grey\nНайдено: Lenovo Legion 5 15IRX9 Storm Grey")
                && str_contains($caption, '⚠️ Не совпадает с запросом. Цвет: в запросе «Luna Grey», найдено «Storm Grey».');
        });
    }

    public function test_a_request_without_differences_shows_no_notice(): void
    {
        [, $draft] = $this->draft(photoCount: 1);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 71]]));

        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '98765', $draft->fresh());

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request['text'] ?? ''), 'Не совпадает с запросом'));
    }

    /** @return array{0: TelegramUpdate, 1: ProductDraft} */
    private function draft(int $photoCount): array
    {
        $update = TelegramUpdate::create(['update_id' => 910215, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => 'Lenovo Legion 5 15IRX9 Luna Grey', 'payload' => ['_progress_message_id' => 7206], 'status' => 'received']);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed',
            'prompt' => 'Fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => 'Lenovo Legion 5 15IRX9 Storm Grey', 'brand' => 'Lenovo',
            'model' => 'Legion 5 15IRX9', 'color' => 'Storm Grey', 'description' => 'Verified description.',
            'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop', 'condition' => 'new',
            'specifications' => [], 'sources' => [['url' => 'https://shop.example/legion', 'title' => 'Legion 5']], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/legion', 'specifications_reconciled_source_url' => 'https://shop.example/legion',
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'cost_budget', 'images_staged_at' => now()]);
        for ($index = 1; $index <= $photoCount; $index++) {
            $path = "drafts/{$draft->id}/{$index}.jpg";
            Storage::disk('public')->put($path, 'fake-image-'.$index);
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'fake-image-'.$index),
                'width' => 1000, 'height' => 800, 'verification_status' => 'verified', 'is_primary' => $index === 1, 'sort_order' => $index]);
        }

        return [$update, $draft];
    }
}
