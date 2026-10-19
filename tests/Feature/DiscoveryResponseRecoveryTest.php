<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Ai\StreamingProductResearch;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\WikimediaImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Tests\TestCase;

class DiscoveryResponseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_IMAGE = 'https://cdn.example/known.jpg';

    public function test_provider_search_events_are_counted_once_per_item_in_the_original_budget(): void
    {
        $data = [...$this->emptyResult(), 'queries_tried' => ['query one', 'query two', 'query three']];
        $this->mock(StreamingProductResearch::class)->shouldReceive('prompt')->once()
            ->andReturnUsing(function ($agent, $prompt, $provider, $model, $timeout, $idle, $onActivity) use ($data) {
                foreach ([['search1', 'searching'], ['search1', 'completed'], ['search2', 'completed']] as [$id, $status]) {
                    $onActivity(new ProviderToolEvent(
                        'event-'.$id.'-'.$status, $id, 'web_search_call', ['status' => $status], $status, time()));
                }

                return new StructuredAgentResponse('test-search', $data, json_encode($data),
                    new Usage(promptTokens: 100, completionTokens: 20), new Meta('openai', 'test'));
            });
        $draft = $this->draft();
        app(ProductImageCandidateDiscovery::class)->find($draft);
        $run = AiRun::firstOrFail();
        $this->assertSame($draft->telegram_update_id, $run->telegram_update_id);
        $this->assertSame(2, $run->usage['web_search_calls']);
        $this->assertSame(100, $run->usage['prompt_tokens']);
        $this->assertCount(2, $run->activity['provider_web_search_items']);
        $this->assertSame('completed', $run->status);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(WikimediaImageSearch::class)->shouldReceive('find')->andReturn([self::KNOWN_IMAGE]);
        $budget = $this->mock(ProductSearchTimeBudget::class);
        $budget->shouldReceive('canStart')->andReturn(true);
        $budget->shouldReceive('timeoutFor')->andReturn(60);
    }

    public function test_unknown_operation_error_gets_one_informed_repair_without_losing_candidates(): void
    {
        $calls = 0;
        ProductImageDiscoveryAgent::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Tool returned an unreadable result.');
            }

            return $this->response($this->emptyResult());
        })->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft));
        $this->assertFalse($discovery->hasTerminalFailure());
        $this->assertSame('image_discovery', AiRun::latest('id')->first()->activity['recovery']['stage']);
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft, searchAttempt: 1));
        $this->assertStringContainsString('Tool returned an unreadable result.', AiRun::latest('id')->first()->prompt);
        $this->assertSame('completed', AiRun::latest('id')->first()->status);
    }

    public function test_the_agent_sees_how_pages_already_opened_in_this_search_ended(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => $this->response($this->emptyResult()))->preventStrayPrompts();
        $draft = $this->draft();
        foreach (['a', 'b'] as $store) {
            foreach ([1, 2] as $round) {
                ProductSourceAttempt::query()->create(['telegram_update_id' => $draft->telegram_update_id,
                    'domain' => "{$store}.campusnet.example",
                    'product_url' => "https://{$store}.campusnet.example/p/x123", 'actor' => 'ai', 'phase' => 'gallery_training',
                    'action' => 'propose_recipe', 'status' => 'interrupted', 'decision' => 'execute_candidate', 'round' => $round]);
            }
        }
        AiOperation::query()->create(['telegram_update_id' => $draft->telegram_update_id, 'telegram_user_id' => '1',
            'tool' => 'AbandonGalleryTrainingAttempt', 'action' => 'abandon_training_attempt', 'status' => 'completed',
            'idempotency_key' => 'abandon-a', 'payload' => ['url' => 'https://a.campusnet.example/p/x123',
                'reason' => 'Frames 02-04 return HTTP 400 on the shared media CDN.']]);

        app(ProductImageCandidateDiscovery::class)->find($draft);

        $prompt = AiRun::latest('id')->firstOrFail()->prompt;
        $this->assertStringContainsString('"pages_opened_this_search"', $prompt);
        $this->assertStringContainsString('"host":"a.campusnet.example","site_name":"campusnet","training_rounds":2,"photos_saved":0,"ended_by_agent":"Frames 02-04 return HTTP 400 on the shared media CDN."', $prompt);
        $this->assertStringContainsString('"failed_site_networks":{"campusnet":{"hosts":["a.campusnet.example","b.campusnet.example"]', $prompt);
    }

    public function test_unknown_operation_error_cannot_purchase_identical_recovery_forever(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => throw new \RuntimeException('Tool failed.'))->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($draft);
        $discovery->find($draft, searchAttempt: 1);
        $this->assertTrue($discovery->hasTerminalFailure());
        $discovery->find($draft, searchAttempt: 2);
        $this->assertSame(2, AiRun::count());
    }

    public function test_invalid_api_key_is_not_delegated_to_an_agent_for_repair(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => throw new \RuntimeException('401 Unauthorized: invalid api key'))->preventStrayPrompts();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($this->draft());
        $this->assertTrue($discovery->hasTerminalFailure());
        $this->assertArrayNotHasKey('recovery', AiRun::first()->activity ?? []);
    }

    public function test_cancellation_is_propagated_and_not_given_to_the_agent_as_a_retry(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => throw new ProductSearchCancelled)->preventStrayPrompts();
        try {
            app(ProductImageCandidateDiscovery::class)->find($this->draft());
            $this->fail('Cancellation must reach the job.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('cancelled', AiRun::first()->status);
            $this->assertSame(1, AiRun::count());
        }
    }

    public function test_already_cancelled_search_never_starts_discovery_or_creates_a_run(): void
    {
        ProductImageDiscoveryAgent::fake()->preventStrayPrompts();
        $draft = $this->draft();
        TelegramUpdate::findOrFail($draft->telegram_update_id)->update(['cancel_requested_at' => now()]);
        try {
            app(ProductImageCandidateDiscovery::class)->find($draft);
            $this->fail('Cancelled search must stop before external work.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(0, AiRun::count());
            ProductImageDiscoveryAgent::assertNeverPrompted();
        }
    }

    public function test_cancellation_while_waiting_for_response_preserves_usage_but_does_not_accept_the_answer(): void
    {
        $draft = $this->draft();
        ProductImageDiscoveryAgent::fake(function () use ($draft) {
            TelegramUpdate::findOrFail($draft->telegram_update_id)->update(['cancel_requested_at' => now()]);

            return $this->response($this->emptyResult());
        })->preventStrayPrompts();
        try {
            app(ProductImageCandidateDiscovery::class)->find($draft);
            $this->fail('A late answer must not restart a cancelled search.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('cancelled', AiRun::first()->status);
            $this->assertSame(100, AiRun::first()->usage['prompt_tokens']);
        }
    }

    public function test_missing_answer_is_a_paid_failure_and_identical_repair_stops(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => $this->response([]))->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft));
        $run = AiRun::latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(100, $run->usage['prompt_tokens']);
        $this->assertSame([], $run->response);
        $this->assertSame('missing_result_fields', $run->activity['response_failure']);
        $this->assertFalse($discovery->hasTerminalFailure());
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft, searchAttempt: 1));
        $this->assertTrue($discovery->hasTerminalFailure());
        $this->assertStringContainsString('missing_result_fields', AiRun::latest('id')->first()->prompt);
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft, searchAttempt: 2));
        $this->assertSame(2, AiRun::count(), 'Do not buy a third identical invalid response.');
        Http::assertNothingSent();
    }

    public function test_agent_can_repair_its_response_without_losing_existing_candidates(): void
    {
        $calls = 0;
        ProductImageDiscoveryAgent::fake(function () use (&$calls) {
            return ++$calls === 1 ? $this->response([]) : $this->response($this->emptyResult());
        })->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($draft);
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft, searchAttempt: 1));
        $this->assertSame('completed', AiRun::latest('id')->first()->status);
        $this->assertFalse($discovery->hasTerminalFailure());
        $this->assertSame(2, $calls);
    }

    public function test_explicit_empty_search_answer_is_valid_and_cached(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => $this->response($this->emptyResult()))->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($draft);
        $discovery->find($draft);
        $this->assertSame('completed', AiRun::first()->status, (string) AiRun::first()->error);
        $this->assertSame(1, AiRun::count());
        $this->assertSame('completed', AiRun::first()->status);
        $this->assertFalse($discovery->hasTerminalFailure());
    }

    public function test_response_recovery_is_scoped_to_one_search(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => $this->response([]))->preventStrayPrompts();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $first = $this->draft();
        $discovery->find($first);
        $discovery->find($first, searchAttempt: 1);
        $this->assertTrue($discovery->hasTerminalFailure());
        $discovery->find($this->draft());
        $this->assertFalse($discovery->hasTerminalFailure());
        $this->assertSame(3, AiRun::count());
    }

    public function test_a_new_time_slice_does_not_replay_the_previous_empty_search_cache(): void
    {
        ProductImageDiscoveryAgent::fake(fn () => $this->response($this->emptyResult()))->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($draft, searchAttempt: 1);
        $discovery->find($draft, searchAttempt: 1);
        $this->assertSame(1, AiRun::count());
        (new ProductSearchTimeBudget(app(AiSettings::class)))->restartSession($draft->telegram_update_id);
        $discovery->find($draft, searchAttempt: 1);
        $this->assertSame(2, AiRun::count());
        $this->assertSame([$draft->telegram_update_id], AiRun::pluck('telegram_update_id')->unique()->values()->all());
        $this->assertStringContainsString('"returned_page_urls":[]', AiRun::latest('id')->first()->prompt);
    }

    public function test_repair_does_not_bypass_the_existing_time_budget(): void
    {
        $calls = 0;
        ProductImageDiscoveryAgent::fake(function () use (&$calls) {
            $calls++;

            return $this->response([]);
        })->preventStrayPrompts();
        $draft = $this->draft();
        $discovery = app(ProductImageCandidateDiscovery::class);
        $discovery->find($draft);
        $budget = $this->mock(ProductSearchTimeBudget::class);
        $budget->shouldReceive('canStart')->once()->andReturn(false);
        $budget->shouldNotReceive('timeoutFor');
        $this->assertSame([self::KNOWN_IMAGE], $discovery->find($draft, searchAttempt: 1));
        $this->assertSame(1, $calls);
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => TelegramUpdate::count() + 800000,
            'telegram_user_id' => '1', 'chat_id' => '1', 'payload' => [], 'text' => 'Find Example X123', 'status' => 'processing']);

        return new ProductDraft(['telegram_update_id' => $update->id, 'title' => 'Example X123',
            'brand' => 'Example', 'model' => 'X123', 'sources' => []]);
    }

    private function emptyResult(): array
    {
        return ['sources' => [], 'image_urls' => [], 'page_urls' => []];
    }

    private function response(array $data): StructuredTextResponse
    {
        return new StructuredTextResponse($data, $data === [] ? '' : json_encode($data),
            new Usage(promptTokens: 100, completionTokens: 20, reasoningTokens: 20), new Meta('openai', 'test'));
    }
}
