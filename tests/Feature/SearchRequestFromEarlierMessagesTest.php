<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ProductSpecificationReconciliationAgent;
use App\Ai\Tools\ResearchProduct;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCostBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductRequestContext;
use App\Services\Products\ProductSpecificationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Live report 2026-11-27, requests 634 and 635: the operator sent a graphics card's code, the
 * search found nothing, and the next message named no product. The assistant
 * searched for the code, but every agent received the follow-up as the operator's request, and
 * the card assembly refused the card because the request named no product. In 706 an
 * instruction about the condition took the place of the product the same way.
 */
class SearchRequestFromEarlierMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://www.gigabyte.com/uk/Graphics-Card/GV-N5070AORUS-IF-12GD';

    public function test_a_follow_up_that_names_no_product_keeps_the_earlier_request_for_every_agent(): void
    {
        $first = $this->message(9634, '9VN5070AIF-00-G10');
        $press = TelegramUpdate::query()->create(['update_id' => 9640, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'text' => 'draft:add:1', 'payload' => ['callback_query' => ['data' => 'draft:add:1']], 'status' => 'completed']);
        $followUp = $this->message(9635, 'Поищи ещё раз', 'processing');

        $draft = $this->research($followUp, ['query' => '9VN5070AIF-00-G10', 'request_messages' => ['9VN5070AIF-00-G10']]);

        $stored = $followUp->fresh()->payload[ProductInputContext::REQUEST_KEY];
        $this->assertSame([$first->id], $stored['message_ids']);
        $this->assertNotContains($press->id, $stored['message_ids']);
        $this->assertSame('Поищи ещё раз', $stored['follow_up']);
        $research = AiRun::query()->where('telegram_update_id', $followUp->id)->where('activity->stage', 'research')->sole();
        $this->assertStringContainsString('"original_operator_request":"9VN5070AIF-00-G10"', $research->prompt);
        $this->assertStringContainsString('"operator_follow_up":"Поищи ещё раз"', $research->prompt);
        $context = ProductRequestContext::forDraft($draft)->forAgent();
        $this->assertSame('9VN5070AIF-00-G10', $context['original_operator_request']);
        $this->assertSame('Поищи ещё раз', $context['operator_follow_up']);

        // The card assembly reads the same request, and the follow-up as an instruction.
        $assembly = null;
        ProductSpecificationReconciliationAgent::fake(function (string $prompt) use (&$assembly): array {
            $assembly = json_decode($prompt, true);

            return ['overall_status' => 'not_ready', 'summary' => 'Проверка.', 'title' => 'x', 'model' => 'x', 'color' => null,
                'description' => 'x', 'specifications' => [], 'unresolved_fields' => []];
        })->preventStrayPrompts();
        app(ProductSpecificationReconciler::class)->reconcile($draft->fresh(), ['url' => self::SOURCE], $followUp->id);
        $this->assertSame('9VN5070AIF-00-G10', $assembly['original_operator_request']);
        $this->assertSame('Поищи ещё раз', $assembly['operator_follow_up']);
    }

    public function test_without_earlier_messages_the_current_message_stays_the_request(): void
    {
        $request = $this->message(9700, '9VN5070AIF-00-G10', 'processing');

        $draft = $this->research($request, ['query' => '9VN5070AIF-00-G10']);

        $context = ProductRequestContext::forDraft($draft)->forAgent();
        $this->assertSame('9VN5070AIF-00-G10', $context['original_operator_request']);
        $this->assertArrayNotHasKey('operator_follow_up', $context);
        $this->assertArrayNotHasKey(ProductInputContext::REQUEST_KEY, (array) $request->fresh()->payload);
    }

    public function test_a_copy_that_matches_no_message_of_the_chat_is_not_taken_as_the_request(): void
    {
        // Another chat's message, or words nobody sent, never become the operator's request.
        TelegramUpdate::query()->create(['update_id' => 9800, 'telegram_user_id' => '777', 'chat_id' => '555',
            'text' => '9VN5070AIF-00-G10', 'payload' => [], 'status' => 'completed']);
        $followUp = $this->message(9801, 'Поищи ещё раз', 'processing');

        $this->assertNull(ProductInputContext::rememberSearchRequest($followUp, ['9VN5070AIF-00-G10']));
        $this->assertSame('Поищи ещё раз', ProductInputContext::forUpdate($followUp)['original_operator_request']);
    }

    public function test_a_label_photo_named_as_the_request_brings_its_reading_as_evidence_never_as_the_operators_words(): void
    {
        // A photo's text is the bot's reading of its label (request 687's label below); taken as
        // the operator's words, a misread code would count as typed.
        $label = 'G.SKILL F5-6000J3038F16GH2-FX5. DDR5-6000, 16GB×2, CL30-38-38-96, 1.35V, AMD EXPO Ready.';
        $photo = TelegramUpdate::query()->create(['update_id' => 9687, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'text' => $label,
            'status' => 'completed', 'payload' => ['message' => ['photo' => [['file_id' => 'x']]], ProductInputContext::PAYLOAD_KEY => [
                'version' => 1, 'original_operator_request' => null,
                'input_evidence' => [['key' => 'mpn', 'name' => 'mpn', 'value' => 'F5-6000J3038F16GH2-FX5', 'raw_value' => 'F5-6000J3038F16GH2-FX5',
                    'source_excerpt' => 'F5-6000J3038F16GH2-FX5', 'source_kind' => 'photo_label', 'source_ref' => 'telegram-photo:9687:1',
                    'photo_ref' => 'telegram-photo:9687:1', 'photo_index' => 1, 'confidence' => 0.95, 'uncertain_characters' => null]],
                'search_keys' => [['kind' => 'mpn', 'key' => 'mpn', 'value' => 'F5-6000J3038F16GH2-FX5', 'source_kind' => 'photo_label',
                    'source_ref' => 'telegram-photo:9687:1', 'confidence' => 0.95, 'uncertain_characters' => null]],
            ]]]);
        $followUp = $this->message(9688, 'Поищи ещё раз', 'processing');

        $stored = ProductInputContext::rememberSearchRequest($followUp, ['G.SKILL F5-6000J3038F16GH2-FX5']);
        $context = ProductInputContext::forUpdate($followUp->fresh());

        $this->assertSame([$photo->id], $stored['message_ids']);
        $this->assertNull($context['original_operator_request']);
        $this->assertSame('Поищи ещё раз', $context['operator_follow_up']);
        $this->assertSame('F5-6000J3038F16GH2-FX5', $context['input_evidence'][0]['value']);
        $this->assertSame('F5-6000J3038F16GH2-FX5', $context['search_plan'][0]['query']);
    }

    private function message(int $updateId, string $text, string $status = 'completed'): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => $updateId, 'telegram_user_id' => '12345', 'chat_id' => '98765',
            'message_id' => $updateId, 'text' => $text, 'payload' => ['message' => ['text' => $text]], 'status' => $status]);
    }

    /** The research answer of request 635 (ai_run 3546), shortened to the fields the card needs. */
    private function research(TelegramUpdate $update, array $arguments): ProductDraft
    {
        Storage::fake('public');
        Cache::flush();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]])]);
        Queue::fake();
        ProductResearchAgent::fake([[
            'status' => 'found', 'title' => 'GIGABYTE AORUS GeForce RTX 5070 INFINITY 12G Graphics Card (9VN5070AIF-00-G10)',
            'brand' => 'GIGABYTE', 'model' => '9VN5070AIF-00-G10', 'product_type' => 'component', 'category' => 'gpu', 'color' => null,
            'condition' => 'new', 'description' => 'The GIGABYTE AORUS GeForce RTX 5070 INFINITY 12G is a graphics card with 12 GB of GDDR7 memory.',
            'research_notes' => 'The operator-supplied code 9VN5070AIF-00-G10 was not observed on the product pages, so its association with this card is unconfirmed.',
            'label_code_misread' => null, 'code_aliases' => [], 'request_differences' => [], 'unconfirmed_operator_codes' => ['9VN5070AIF-00-G10'],
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '9VN5070AIF-00-G10', 'group' => null],
                ['key' => 'vram', 'name' => 'Video memory', 'value' => '12 GB GDDR7', 'group' => null,
                    'source_url' => self::SOURCE.'/sp', 'source_excerpt' => 'Memory Size 12 GB; Memory Type GDDR7'],
            ],
            'sources' => [
                ['title' => 'AORUS GeForce RTX 5070 INFINITY 12G Graphics Card', 'url' => self::SOURCE, 'type' => 'manufacturer',
                    'roles' => ['facts', 'exact_photo'], 'applicability_reason' => 'Manufacturer product page identifies the observed model and is the product page with its gallery.', 'image_urls' => []],
                ['title' => 'AORUS GeForce RTX 5070 INFINITY 12G Graphics Card Specifications', 'url' => self::SOURCE.'/sp', 'type' => 'manufacturer',
                    'roles' => ['facts'], 'applicability_reason' => 'Manufacturer specification page identifies the observed model.', 'image_urls' => []],
            ],
            'primary_source_url' => self::SOURCE, 'official_source_url' => null, 'image_urls' => [], 'confidence' => 0.87,
            'selected_identity' => $this->observedIdentity('GV-N5070AORUS-IF-12GD', self::SOURCE),
        ]])->preventStrayPrompts();
        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldNotReceive('resolve');
        $storage = $this->mock(ProductImageStorage::class);
        $storage->shouldReceive('stage')->once()->andReturnUsing(function (ProductDraft $draft): int {
            $draft->update(['images_staged_at' => now(), 'gallery_status' => 'complete']);
            $path = "drafts/{$draft->id}/0.webp";
            Storage::disk('public')->put($path, 'photo');
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'source_url' => self::SOURCE.'/0.webp', 'mime_type' => 'image/webp',
                'width' => 1600, 'height' => 1200, 'checksum' => hash('sha256', $path), 'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

            return 1;
        });
        $costBudget = $this->mock(ProductSearchCostBudget::class);
        $costBudget->shouldReceive('hasRemaining')->andReturn(false);
        $costBudget->shouldReceive('exceeded')->andReturn(false);

        $result = json_decode((string) (new ResearchProduct($update, $resolver, imageStorage: $storage))
            ->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);

        return ProductDraft::query()->findOrFail($result['draft_id']);
    }
}
