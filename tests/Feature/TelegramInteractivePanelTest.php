<?php

namespace Tests\Feature;

use App\Jobs\ProcessDraftPhotoActions;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\StoreProductImages;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramMessageLifecycle;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramInteractivePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Cache::flush();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);

        Http::fake([
            'https://example.com/product.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    public function test_start_command_shows_the_main_keyboard_without_starting_ai(): void
    {
        Queue::fake();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3001,
            'message' => [
                'message_id' => 70,
                'from' => ['id' => 12345, 'username' => 'admin'],
                'chat' => ['id' => 98765],
                'text' => '/start',
            ],
        ], $this->headers())->assertOk();

        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3001,
            'status' => 'command',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['reply_markup']['keyboard'][0][0]['text'] === '🔎 Найти товар'
        );
    }

    public function test_admin_can_approve_a_draft_with_an_inline_button(): void
    {
        Queue::fake();
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => 3002,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 71,
            'text' => 'Find a laptop',
            'payload' => ['update_id' => 3002],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Find a laptop',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Lenovo Legion',
            'brand' => 'Lenovo',
            'model' => 'Legion',
            'description' => "Closest product found for the user's request. This is a family-level match rather than one exact SKU.",
            'specifications' => [
                ['name' => 'RAM', 'value' => '32 GB DDR5'],
            ],
            'sources' => [['title' => 'Store', 'url' => 'https://example.com/product']],
            'image_urls' => ['https://example.com/product.jpg'],
            'confidence' => 0.8,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3003,
            'callback_query' => [
                'id' => 'callback-1',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:approve:{$draft->id}",
                'message' => [
                    'message_id' => 72,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertDatabaseHas('product_drafts', [
            'id' => $draft->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('products', [
            'title' => 'Lenovo Legion',
            'status' => 'published',
            'is_active' => true,
        ]);
        $product = Product::query()->where('title', 'Lenovo Legion')->firstOrFail();
        $this->assertStringNotContainsString('Closest', (string) $product->description);
        $this->assertStringContainsString('RAM: 32 GB DDR5', (string) $product->description);
        $this->assertStringContainsString('family-level match', (string) $draft->fresh()->research_notes);
        $this->assertDatabaseHas('product_variants', [
            'name' => 'Базовая конфигурация',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->assertNotNull($draft->fresh()->approved_product_id);
        $this->assertNotNull($draft->fresh()->approved_variant_id);
        $this->assertDatabaseHas('brands', ['name' => 'Lenovo', 'slug' => 'lenovo']);
        $this->assertDatabaseHas('attribute_values', [
            'product_variant_id' => $draft->fresh()->approved_variant_id,
            'value' => '32 GB DDR5',
            'numeric_value' => 32,
            'unit' => 'GB',
        ]);
        $this->assertDatabaseHas('product_sources', [
            'product_draft_id' => $draft->id,
            'url' => 'https://example.com/product',
        ]);
        $this->assertDatabaseCount('product_media', 0);
        Queue::assertPushed(StoreProductImages::class, fn (StoreProductImages $job): bool => $job->draftId === $draft->id);
        $this->assertDatabaseHas('telegram_updates', [
            'update_id' => 3003,
            'status' => 'completed',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'callback-1'
        );
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup')
            && $request['reply_markup']['inline_keyboard'] === []
        );
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage'));
    }

    public function test_enhance_button_opens_numbered_draft_photo_selection(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3101,
            'callback_query' => [
                'id' => 'callback-enhance-menu',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:enhance:{$draft->id}",
                'message' => [
                    'message_id' => 80,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), "Какое фото черновика #{$draft->id}")
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.text') === '1️⃣'
            && str_starts_with(
                (string) data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data'),
                "draft:enhance-photo:{$draft->id}:",
            ));
    }

    public function test_replace_button_photo_selection_offers_an_all_photos_restage_shortcut(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3102,
            'callback_query' => [
                'id' => 'callback-replace-menu',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:replace:{$draft->id}",
                'message' => [
                    'message_id' => 80,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(function (ClientRequest $request) use ($draft): bool {
            if (! str_ends_with($request->url(), '/sendMessage')) {
                return false;
            }
            $rows = data_get($request['reply_markup'] ?? [], 'inline_keyboard', []);
            $allPhotosRow = collect($rows)->flatten(1)->firstWhere('callback_data', "draft:restage:{$draft->id}");

            return $allPhotosRow !== null && $allPhotosRow['text'] === '🔁 Все фото';
        });
    }

    public function test_menu_transitions_reuse_the_existing_control_message_instead_of_piling_up(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();
        $draft->forceFill(['telegram_control_message_ids' => [80]])->save();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3103,
            'callback_query' => [
                'id' => 'callback-replace-menu-reuse',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:replace:{$draft->id}",
                'message' => [
                    'message_id' => 80,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) $request['message_id'] === 80
            && str_contains((string) ($request['text'] ?? ''), "Какое фото черновика #{$draft->id}"));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), "Какое фото черновика #{$draft->id}"));
        $this->assertSame([80], $draft->fresh()->telegram_control_message_ids);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3104,
            'callback_query' => [
                'id' => 'callback-review-back',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:review:{$draft->id}",
                'message' => [
                    'message_id' => 80,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageText')
            && (int) $request['message_id'] === 80
            && str_contains((string) ($request['text'] ?? ''), "Итого: черновик #{$draft->id}"));
        $this->assertSame([80], $draft->fresh()->telegram_control_message_ids);
    }

    public function test_rejecting_a_draft_edits_the_review_post_in_place_instead_of_posting_a_new_message(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();
        $draft->forceFill([
            'telegram_review_chat_id' => '98765',
            'telegram_review_message_ids' => [77],
            'telegram_review_has_media' => true,
            'telegram_review_caption' => "🆕 Черновик #{$draft->id} готов к добавлению\n\nSome details.",
            'telegram_control_message_ids' => [80],
        ])->save();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3105,
            'callback_query' => [
                'id' => 'callback-reject',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:reject:{$draft->id}",
                'message' => [
                    'message_id' => 80,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageCaption')
            && (int) $request['message_id'] === 77
            && str_starts_with((string) ($request['caption'] ?? ''), "✖ Черновик #{$draft->id} отклонён."));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'отклонён'));
        $this->assertSame('rejected', $draft->fresh()->status);
    }

    public function test_a_new_review_response_clears_the_stale_control_message_instead_of_reusing_it(): void
    {
        $draft = $this->pendingDraftWithMedia();
        $draft->forceFill(['telegram_control_message_ids' => [555]])->save();

        $lifecycle = app(DraftTelegramMessageLifecycle::class);
        $telegram = app(TelegramClient::class);

        $lifecycle->rememberReviewResponse($telegram, $draft, '98765', [
            'ok' => true,
            'result' => [['message_id' => 950], ['message_id' => 951]],
        ], true, 'caption');

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/deleteMessages')
            && in_array(555, $request['message_ids'] ?? [], true));

        $fresh = $draft->fresh();
        $this->assertSame([], $fresh->telegram_control_message_ids);
        $this->assertSame([950, 951], $fresh->telegram_review_message_ids);
    }

    public function test_selecting_a_draft_photo_queues_enhancement_and_reports_progress(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();
        $media = $draft->media()->orderBy('sort_order')->firstOrFail();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3102,
            'callback_query' => [
                'id' => 'callback-enhance-photo',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:enhance-photo:{$draft->id}:{$media->id}",
                'message' => [
                    'message_id' => 81,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Queue::assertPushed(ProcessDraftPhotoActions::class, fn (ProcessDraftPhotoActions $job): bool => $job->draftId === $draft->id
            && data_get($job->actions, '0.action') === 'enhance'
            && data_get($job->actions, '0.media_id') === $media->id
            && $job->chatId === '98765');
        $this->assertDatabaseHas('ai_operations', [
            'action' => 'enhance_draft_photo',
            'target_id' => $media->id,
            'status' => 'running',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Улучшаю фото 1'));
    }

    public function test_retrain_source_button_queues_ai_recipe_training(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3103,
            'callback_query' => [
                'id' => 'callback-retrain-source',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:retrain:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Queue::assertPushed(TrainDraftGalleryRecipe::class, fn (TrainDraftGalleryRecipe $job): bool => $job->draftId === $draft->id
            && $job->chatId === '98765');
    }

    public function test_source_button_opens_the_source_submenu(): void
    {
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3300,
            'callback_query' => [
                'id' => 'callback-source-menu',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'amazon.com')
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data') === "draft:source-retrain:{$draft->id}"
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.1.0.callback_data') === "draft:source-hint:{$draft->id}"
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.2.0.callback_data') === "draft:source-block:{$draft->id}");
    }

    public function test_source_submenu_retrain_button_queues_ai_recipe_training(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3301,
            'callback_query' => [
                'id' => 'callback-source-retrain',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source-retrain:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        Queue::assertPushed(TrainDraftGalleryRecipe::class, fn (TrainDraftGalleryRecipe $job): bool => $job->draftId === $draft->id
            && $job->chatId === '98765'
            && $job->hint === null);
    }

    public function test_source_hint_button_captures_the_next_message_as_a_hint_instead_of_a_new_search(): void
    {
        Queue::fake();
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3302,
            'callback_query' => [
                'id' => 'callback-source-hint',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source-hint:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3303,
            'message' => [
                'message_id' => 83,
                'from' => ['id' => 12345, 'username' => 'admin'],
                'chat' => ['id' => 98765],
                'text' => 'На странице таблица с разными моделями, фото путается.',
            ],
        ], $this->headers())->assertOk();

        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Queue::assertPushed(
            TrainDraftGalleryRecipe::class,
            fn (TrainDraftGalleryRecipe $job): bool => $job->draftId === $draft->id
                && $job->chatId === '98765'
                && $job->hint === 'На странице таблица с разными моделями, фото путается.',
        );
    }

    public function test_a_plain_search_after_the_hint_window_expires_is_not_swallowed_as_a_hint(): void
    {
        Queue::fake();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3304,
            'message' => [
                'message_id' => 84,
                'from' => ['id' => 12345, 'username' => 'admin'],
                'chat' => ['id' => 98765],
                'text' => 'Найди ASUS Vivobook 16',
            ],
        ], $this->headers())->assertOk();

        Queue::assertPushed(ProcessTelegramMessage::class);
        Queue::assertNotPushed(TrainDraftGalleryRecipe::class);
    }

    public function test_source_block_button_asks_for_confirmation_before_blocking_the_domain(): void
    {
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3305,
            'callback_query' => [
                'id' => 'callback-source-block-prompt',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source-block:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertDatabaseMissing('product_gallery_recipes', ['domain' => 'amazon.com']);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'amazon.com')
            && data_get($request['reply_markup'] ?? [], 'inline_keyboard.0.0.callback_data') === "draft:source-block-confirm:{$draft->id}");
    }

    public function test_source_block_confirm_blocks_the_domain_for_all_future_searches(): void
    {
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3306,
            'callback_query' => [
                'id' => 'callback-source-block-confirm',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source-block-confirm:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertDatabaseHas('product_gallery_recipes', [
            'domain' => 'amazon.com',
            'path_pattern' => '*',
            'source_blocked' => true,
            'status' => 'disabled',
        ]);
    }

    public function test_source_block_cancel_leaves_the_domain_untouched(): void
    {
        $draft = $this->pendingDraftWithMedia();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3307,
            'callback_query' => [
                'id' => 'callback-source-block-cancel',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "draft:source-block-cancel:{$draft->id}",
                'message' => [
                    'message_id' => 82,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertDatabaseMissing('product_gallery_recipes', ['domain' => 'amazon.com']);
    }

    public function test_search_cancel_button_marks_the_update_and_strips_its_own_keyboard(): void
    {
        $searching = TelegramUpdate::query()->create([
            'update_id' => 3200,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'text' => 'Acer Nitro V16',
            'payload' => [],
            'status' => 'processing',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3201,
            'callback_query' => [
                'id' => 'callback-cancel-search',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "search:cancel:{$searching->id}",
                'message' => [
                    'message_id' => 90,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertNotNull($searching->fresh()->cancel_requested_at);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'callback-cancel-search');
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/editMessageReplyMarkup')
            && (int) $request['message_id'] === 90
            && $request['reply_markup']['inline_keyboard'] === []);
    }

    public function test_search_cancel_button_on_an_already_finished_search_does_nothing_harmful(): void
    {
        $finished = TelegramUpdate::query()->create([
            'update_id' => 3202,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'text' => 'Acer Nitro V16',
            'payload' => [],
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 3203,
            'callback_query' => [
                'id' => 'callback-cancel-late',
                'from' => ['id' => 12345, 'username' => 'admin'],
                'data' => "search:cancel:{$finished->id}",
                'message' => [
                    'message_id' => 91,
                    'chat' => ['id' => 98765],
                ],
            ],
        ], $this->headers())->assertOk();

        $this->assertNull($finished->fresh()->cancel_requested_at);
        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && str_contains((string) $request['text'], 'уже завершён'));
    }

    private function pendingDraftWithMedia(): ProductDraft
    {
        $sourceUpdate = TelegramUpdate::query()->create([
            'update_id' => random_int(10000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 79,
            'text' => 'Find exact product',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => 'completed',
            'prompt' => 'Find exact product',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $sourceUpdate->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'MSI Aegis Exact',
            'brand' => 'MSI',
            'model' => 'Aegis Exact',
            'color' => 'Black',
            'description' => 'Exact product description.',
            'specifications' => [],
            'sources' => [['title' => 'Amazon', 'url' => 'https://amazon.com/dp/EXACT', 'type' => 'marketplace']],
            'primary_source_url' => 'https://amazon.com/dp/EXACT',
            'image_urls' => [],
            'images_staged_at' => now(),
            'confidence' => 0.95,
        ]);

        foreach (range(1, 3) as $position) {
            $path = "drafts/{$draft->id}/photo-{$position}.webp";
            Storage::disk('public')->put($path, "photo-{$position}");
            $draft->media()->create([
                'disk' => 'public',
                'path' => $path,
                'source_url' => "https://images.example/photo-{$position}.jpg",
                'role' => $position === 1 ? 'primary' : 'secondary',
                'mime_type' => 'image/webp',
                'checksum' => hash('sha256', "photo-{$position}"),
                'verification_status' => 'verified',
                'sort_order' => $position - 1,
                'is_primary' => $position === 1,
            ]);
        }

        return $draft;
    }

    private function headers(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(900, 700);
        $background = imagecolorallocate($image, 240, 240, 240);
        $foreground = imagecolorallocate($image, 20, 30, 40);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 140, 120, 760, 580, $foreground);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }
}
