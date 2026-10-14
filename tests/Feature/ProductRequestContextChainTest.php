<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Ai\Agents\ProductImageDiscoveryAgent;
use App\Ai\Tools\GetProductSearchIntent;
use App\Ai\Tools\InspectGalleryImages;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\AiModel;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\OperatorDomainHintRecorder;
use App\Services\Products\ProductCardAssembly;
use App\Services\Products\ProductGalleryRecipeTrainer;
use App\Services\Products\ProductImageCandidateDiscovery;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\SpecificationReconciliationOutcome;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request as ToolRequest;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The request a source page is judged against must reach every agent that
 * judges it - the gallery preflight, the recipe trainer, and the trainer's
 * intent and Vision tools - whichever path started the run. Archived case:
 * the operator asked for "MSI Stealth 16 AI A2HWGG-009IT", the search was
 * continued by a button press, and the trainer was told the request was
 * "draft:continue-search:8".
 *
 * Every scenario enters through the production entry point with only the
 * browser process and the model calls replaced; nothing here assembles the
 * context the agents receive.
 */
class ProductRequestContextChainTest extends TestCase
{
    use RefreshDatabase;

    private const string OPERATOR_REQUEST = "Stealth 16 AI A2HWGG\nMKT: Stealth 16 AI A2HWGG-009IT\nSKU: BBU9285H32GXXDX11MA\n9S7-15F515-009";

    private const string PAGE_URL = 'https://93.184.216.34/notebook-msi-stealth-16-ai-a2hwgg-009it';

    private const string GALLERY_IMAGE = 'https://93.184.216.34/gallery/stealth-1.jpg';

    private const string RETRAINING_HINT = 'берёт не тот цвет';

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $prompts = ['preflight' => [], 'trainer' => [], 'vision' => []];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        config()->set('product-images.browser_fallback.enabled', true);
        AppSetting::put('ai.fallback_sources_enabled', '0');

        $jpeg = $this->jpeg();
        Http::fake(function (Request $request) use ($jpeg) {
            if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '.jpg')) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            if (str_starts_with($request->url(), self::PAGE_URL)) {
                return Http::response(
                    '<html><head><title>MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009</title></head>'
                    .'<body><h1>MSI Stealth 16 AI A2HWGG-009IT</h1><p>MPN 9S7-15F515-009</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                );
            }

            return Http::response('', 404);
        });

        ProductGalleryPreflightAgent::fake(function (string $prompt): array {
            $this->prompts['preflight'][] = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

            return [
                'decision' => 'train_playwright',
                'page_kind' => 'product_card',
                'gallery_likely' => true,
                'hidden_images_likely' => true,
                'interaction_required' => true,
                'expected_image_count' => 3,
                'evidence' => ['A product gallery needs interaction.'],
                'confidence' => 0.9,
                'reason' => 'Gallery needs interaction.',
            ];
        })->preventStrayPrompts();
        ProductGalleryRecipeTrainerAgent::fake(function (string $prompt): array {
            $this->prompts['trainer'][] = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

            return [
                'gallery_present' => true,
                'content_confirmed_product' => true,
                'expected_image_count' => 3,
                'expected_count_evidence' => 'Three thumbnails in the product gallery.',
                'pre_click_selectors' => [],
                'collect_selectors' => ['.gallery img'],
                'thumbnail_selectors' => [],
                'open_selectors' => [],
                'next_selectors' => [],
                'attributes' => ['src'],
                'max_thumbnail_clicks' => 0,
                'max_next_clicks' => 0,
                'wait_after_click_ms' => 200,
                'confidence' => 0.8,
                'reason' => 'Collect the gallery images.',
            ];
        })->preventStrayPrompts();
        ProductGalleryVisionAgent::fake(function (string $prompt): array {
            $this->prompts['vision'][] = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

            return [
                'coherent_single_product_gallery' => true,
                'needs_more_evidence' => false,
                'images' => [],
                'summary' => 'Observed.',
            ];
        })->preventStrayPrompts();
        ProductImageDiscoveryAgent::fake(fn (): array => [
            'sources' => [], 'image_urls' => [], 'page_urls' => [],
        ])->preventStrayPrompts();

        // Only what starts a browser process is replaced. extract(), the
        // resolver in front of it and the trainer behind it run for real.
        $dependencies = array_map(
            fn ($parameter) => app((string) $parameter->getType()),
            (new \ReflectionClass(BrowserProductGalleryExtractor::class))->getConstructor()->getParameters(),
        );
        $browser = Mockery::mock(BrowserProductGalleryExtractor::class, $dependencies)->makePartial();
        $browser->shouldReceive('scout')->andReturn(['scout' => [
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT',
            'fragments' => ['<div class="gallery"><img src="'.self::GALLERY_IMAGE.'"></div>'],
            'image_candidates' => [['src' => self::GALLERY_IMAGE]],
            'access_gate' => false,
            'rate_limited' => false,
        ]]);
        $browser->shouldReceive('scoutAfterOpening')->andReturn([]);
        $browser->shouldReceive('executeRecipe')->andReturn(['images' => [], 'diagnostics' => []]);
        $this->app->instance(BrowserProductGalleryExtractor::class, $browser);
    }

    /** @return array<string, array{0: string}> */
    public static function runsBoundToADraft(): array
    {
        return [
            'first search' => ['stage'],
            'continued search' => ['continue'],
            'photo top-up' => ['top_up'],
            'fallback discovery' => ['fallback'],
            'manual retraining' => ['retrain'],
        ];
    }

    #[DataProvider('runsBoundToADraft')]
    public function test_every_agent_judging_the_page_receives_the_drafts_request(string $path): void
    {
        $draft = $this->draft();
        $runId = $this->startRun($path, $draft);

        $this->assertNotEmpty($this->prompts['preflight'], 'the preflight was never asked');
        $this->assertNotEmpty($this->prompts['trainer'], 'the trainer was never asked');
        $expectedHint = $path === 'retrain' ? self::RETRAINING_HINT : null;

        foreach ([...$this->prompts['preflight'], ...$this->prompts['trainer']] as $received) {
            $this->assertRequestFromDraft($received, $expectedHint);
        }

        // The trainer's own tools, exactly as the training loop built them.
        $tools = $this->trainerToolsFromTheLastRound();
        $intent = collect($tools)->first(fn (object $tool): bool => $tool instanceof GetProductSearchIntent);
        $vision = collect($tools)->first(fn (object $tool): bool => $tool instanceof InspectGalleryImages);
        $this->assertNotNull($intent);
        $this->assertNotNull($vision);
        $this->assertRequestFromDraft(json_decode((string) $intent->handle(new ToolRequest), true, flags: JSON_THROW_ON_ERROR), $expectedHint);

        $vision->handle(new ToolRequest(['image_urls' => [self::GALLERY_IMAGE], 'product_context' => 'Stealth 16 AI']));
        $this->assertCount(1, $this->prompts['vision']);
        $this->assertRequestFromDraft($this->prompts['vision'][0], $expectedHint);

        // The request is the draft's; the spending is the run's.
        $agentRuns = AiRun::query()
            ->where('prompt', 'like', '%original_operator_request_status%')
            ->pluck('telegram_update_id')
            ->unique()
            ->values()
            ->all();
        $this->assertSame([$runId], $agentRuns);
    }

    public function test_a_run_bound_to_no_draft_says_so_instead_of_reading_the_latest_message(): void
    {
        $latestMessage = TelegramUpdate::query()->create([
            'update_id' => 99100,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'completed',
            'text' => 'а теперь найди мне Lenovo Legion 5',
        ]);

        app(ProductGalleryRecipeTrainer::class)->train(
            self::PAGE_URL,
            force: true,
            telegramUpdateId: $latestMessage->id,
        );

        $this->assertNotEmpty($this->prompts['trainer']);
        foreach ([...$this->prompts['preflight'], ...$this->prompts['trainer']] as $received) {
            $this->assertNull($received['original_operator_request']);
            $this->assertSame('unavailable', $received['original_operator_request_status']);
            $this->assertNull($received['selected_product']);
            $this->assertStringNotContainsString('Lenovo Legion', json_encode($received, JSON_UNESCAPED_UNICODE));
        }
        $intent = collect($this->trainerToolsFromTheLastRound())
            ->first(fn (object $tool): bool => $tool instanceof GetProductSearchIntent);
        $this->assertSame(
            'unavailable',
            json_decode((string) $intent->handle(new ToolRequest), true, flags: JSON_THROW_ON_ERROR)['original_operator_request_status'],
        );
    }

    public function test_a_continuation_whose_own_budget_is_spent_calls_no_model_although_the_original_search_has_budget_left(): void
    {
        $draft = $this->draft();
        $press = $this->buttonPress($draft);
        $this->spendBeyondTheLimit($press);
        // Spent money stops photo work, not the card: with no photos the card
        // is still assembled, as text, against the page research chose
        // (PROJECT_STRATEGY: "Сборка карточки не ограничивается лимитами поиска").
        $this->mock(ProductCardAssembly::class)->shouldReceive('complete')->once()
            ->withArgs(fn (ProductDraft $draft, array $source): bool => $source['url'] === self::PAGE_URL)
            ->andReturn(SpecificationReconciliationOutcome::alreadyReconciled(self::PAGE_URL));

        app(ProductImageStorage::class)->continueStage($draft->fresh(), null, $press->id);

        ProductGalleryPreflightAgent::assertNeverPrompted();
        ProductGalleryRecipeTrainerAgent::assertNeverPrompted();
        ProductGalleryVisionAgent::assertNeverPrompted();
        ProductImageDiscoveryAgent::assertNeverPrompted();
        $this->assertSame(1, AiRun::query()->where('telegram_update_id', $press->id)->count(), 'only the pre-existing spend');
        $this->assertSame('cost_budget', $draft->fresh()->gallery_search_stop_reason);
        $this->assertSame(self::PAGE_URL, $draft->fresh()->specifications_reconciled_source_url);
    }

    /**
     * The mirror of the test above, and the reason it proves anything: with
     * the same draft and the same source, the only difference is which run
     * has already spent its money.
     */
    public function test_a_continuation_spends_its_own_budget_when_only_the_original_search_is_spent(): void
    {
        $draft = $this->draft();
        $press = $this->buttonPress($draft);
        $this->spendBeyondTheLimit(TelegramUpdate::query()->findOrFail($draft->telegram_update_id));

        app(ProductImageStorage::class)->continueStage($draft->fresh(), null, $press->id);

        $this->assertNotEmpty($this->prompts['preflight']);
        $this->assertNotEmpty($this->prompts['trainer']);
        $this->assertSame(
            [$press->id],
            AiRun::query()->where('prompt', 'like', '%original_operator_request_status%')
                ->pluck('telegram_update_id')->unique()->values()->all(),
        );
    }

    private function buttonPress(ProductDraft $draft): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => 99002,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => ['callback_query' => ['data' => "draft:continue-search:{$draft->id}"]],
            'status' => 'command',
            'text' => "draft:continue-search:{$draft->id}",
        ]);
    }

    /** A real, priced model call recorded against the run, far above the configured limit. */
    private function spendBeyondTheLimit(TelegramUpdate $run): void
    {
        AppSetting::put('ai.max_search_cost_usd', '0.50');
        AiModel::query()->where('provider', 'openai')->where('model', 'gpt-5.4')->update([
            'input_per_million' => 2.50,
            'cached_input_per_million' => 0.25,
            'output_per_million' => 15.00,
        ]);
        AiRun::query()->create([
            'telegram_update_id' => $run->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'earlier work of this run',
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /** Runs the path through its production entry point and returns the run's own update id. */
    private function startRun(string $path, ProductDraft $draft): int
    {
        if ($path === 'stage') {
            app(ProductImageStorage::class)->stage($draft->fresh(), null, $draft->telegram_update_id);

            return $draft->telegram_update_id;
        }

        $press = $this->buttonPress($draft);

        match ($path) {
            'continue' => app(ProductImageStorage::class)->continueStage($draft->fresh(), null, $press->id),
            'top_up' => app(ProductImageStorage::class)->topUpDraftMedia($draft->fresh(), null, $press->id),
            'fallback' => app(ProductImageCandidateDiscovery::class)->find($draft->fresh(), telegramUpdateId: $press->id),
            'retrain' => $this->retrain($draft, $press),
        };

        return $press->id;
    }

    private function retrain(ProductDraft $draft, TelegramUpdate $press): void
    {
        $telegram = $this->mock(TelegramClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendMessage')->andReturn(['result' => ['message_id' => 1]]);
            $mock->shouldReceive('editMessageText')->andReturn([]);
        });

        try {
            (new TrainDraftGalleryRecipe($draft->id, $press->id, '1', self::RETRAINING_HINT, $draft->telegram_update_id))
                ->handle(app(ProductGalleryRecipeTrainer::class), $telegram, app(OperatorDomainHintRecorder::class));
        } catch (RuntimeException) {
            // No gallery comes back from the replaced browser; the job reports
            // that, which is not what this test is about.
        }
    }

    /** @return array<int, object> */
    private function trainerToolsFromTheLastRound(): array
    {
        $tools = [];
        ProductGalleryRecipeTrainerAgent::assertPrompted(function (AgentPrompt $prompt) use (&$tools): bool {
            $tools = iterator_to_array($prompt->agent->tools(), false);

            return true;
        });

        return $tools;
    }

    /** @param array<string, mixed> $received */
    private function assertRequestFromDraft(array $received, ?string $expectedHint): void
    {
        $this->assertSame(self::OPERATOR_REQUEST, $received['original_operator_request']);
        $this->assertSame('provided', $received['original_operator_request_status']);
        $this->assertSame('Stealth 16 AI A2HWGG-009IT', $received['selected_product']['model']);
        $this->assertContains(['key' => 'mpn', 'value' => '9S7-15F515-009'], $received['selected_product']['identifiers']);
        $this->assertSame('Core Black', $received['selected_product']['color']);
        $this->assertContains('32 GB', array_column($received['selected_product']['configuration'], 'value'));
        $this->assertSame($expectedHint, $received['operator_hint']);
        $this->assertStringNotContainsString('draft:', json_encode($received, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function draft(): ProductDraft
    {
        Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops']);
        $request = TelegramUpdate::query()->create([
            'update_id' => 99001,
            'telegram_user_id' => '1',
            'chat_id' => '1',
            'payload' => [],
            'status' => 'completed',
            'text' => self::OPERATOR_REQUEST,
        ]);
        $research = AiRun::query()->create([
            'telegram_update_id' => $request->id,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'research',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $request->id,
            'ai_run_id' => $research->id,
            'status' => 'pending_review',
            'requested_by_telegram_user_id' => '1',
            'title' => 'MSI Stealth 16 AI A2HWGG-009IT',
            'brand' => 'MSI',
            'model' => 'Stealth 16 AI A2HWGG-009IT',
            'product_type' => 'laptop',
            'category' => 'laptops',
            'color' => 'Core Black',
            'specifications' => [
                ['key' => 'mpn', 'name' => 'MPN', 'value' => '9S7-15F515-009'],
                ['key' => 'sku', 'name' => 'SKU', 'value' => 'BBU9285H32GXXDX11MA'],
                ['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB'],
            ],
            'sources' => [[
                'title' => 'MSI Stealth 16 AI A2HWGG-009IT 9S7-15F515-009',
                'url' => self::PAGE_URL,
                'type' => 'retailer',
                'image_urls' => [],
            ]],
            'primary_source_url' => self::PAGE_URL,
            'image_urls' => [],
            'confidence' => 1,
        ]);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 40, 40));
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }
}
