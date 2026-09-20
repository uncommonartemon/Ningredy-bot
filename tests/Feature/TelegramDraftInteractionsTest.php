<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramMessage;
use App\Jobs\RestageDraftGalleryPhotos;
use App\Jobs\TrainDraftGalleryRecipe;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramDraftInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 88000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['123', '456']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 700]])]);
    }

    public function test_rejection_requires_confirmation_and_back_preserves_the_draft(): void
    {
        $draft = $this->draft();
        $this->press("draft:reject:{$draft->id}");
        $state = app(DraftTelegramInteractionState::class)->get('900', '123');
        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->press("draft:review:{$draft->id}");
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        $this->assertSame('pending_review', $draft->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_confirmed_rejection_happens_once_without_starting_work(): void
    {
        $draft = $this->draft();
        $this->press("draft:reject:{$draft->id}");
        $state = app(DraftTelegramInteractionState::class)->get('900', '123');
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        $this->assertSame('rejected', $draft->fresh()->status);
        $this->assertDatabaseCount('ai_operations', 1);
        Queue::assertNothingPushed();
    }

    public function test_new_and_reset_clear_pending_source_hints_including_legacy_keys(): void
    {
        foreach (['/new', '/reset'] as $command) {
            $draft = $this->draft();
            $this->press("draft:source-hint:{$draft->id}");
            Cache::put('draft-source-hint-await:900', $draft->id, 600);
            $this->message($command);
            $this->assertNull(app(DraftTelegramInteractionState::class)->get('900', '123'));
            $this->assertNull(Cache::get('draft-source-hint-await:900'));
            $this->message('Найди другой ноутбук');
        }
        Queue::assertPushed(ProcessTelegramMessage::class, 2);
        Queue::assertNotPushed(TrainDraftGalleryRecipe::class);
    }

    public function test_hint_has_cancel_and_does_not_consume_another_operators_message(): void
    {
        $draft = $this->draft();
        $this->press("draft:source-hint:{$draft->id}");
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), "draft:input-cancel:{$draft->id}"));
        $this->message('Найди SSD', 456);
        Queue::assertNotPushed(TrainDraftGalleryRecipe::class);
        $this->assertNotNull(app(DraftTelegramInteractionState::class)->get('900', '123'));
        $this->press("draft:input-cancel:{$draft->id}");
        $this->message('Найди Lenovo');
        Queue::assertPushed(ProcessTelegramMessage::class, 2);
        Queue::assertNotPushed(TrainDraftGalleryRecipe::class);
    }

    public function test_hint_cannot_target_a_replaced_draft_generation(): void
    {
        $draft = $this->draft();
        $this->press("draft:source-hint:{$draft->id}");
        $replacement = $this->draft();
        $draft->update(['telegram_update_id' => $replacement->telegram_update_id]);
        $this->message('Открой главное фото');
        Queue::assertNothingPushed();
    }

    public function test_draft_list_opens_current_controls_instead_of_publishing_from_list(): void
    {
        $draft = $this->draft();
        $this->message('/drafts');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && data_get($r->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "draft:open:{$draft->id}:{$draft->telegram_update_id}");
        $this->press("draft:open:{$draft->id}:{$draft->telegram_update_id}", message: 999);
        $this->assertSame([700], $draft->fresh()->telegram_control_message_ids);
        $this->assertSame('pending_review', $draft->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_open_is_bound_to_owner_chat_and_generation(): void
    {
        $draft = $this->draft();
        $this->press("draft:open:{$draft->id}:{$draft->telegram_update_id}", user: 456);
        $this->press("draft:open:{$draft->id}:{$draft->telegram_update_id}", chat: 901);
        $this->press("draft:open:{$draft->id}:999999");
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'));
        Queue::assertNothingPushed();
    }

    public function test_edit_menu_is_free_and_keeps_one_control_message(): void
    {
        $draft = $this->draft();
        $this->press("draft:edit:{$draft->id}");
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && (int) $r['message_id'] === 700
            && str_contains(json_encode($r->data()), "draft:query:{$draft->id}"));
        $this->assertSame([700], $draft->fresh()->telegram_control_message_ids);
        $this->assertSame('pending_review', $draft->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_main_controls_offer_fix_beside_reject_and_never_publish_without_photos(): void
    {
        $draft = $this->draft();
        app(DraftTelegramPresenter::class)->sendControls(app(TelegramClient::class), '900', $draft);
        Http::assertSent(function (Request $r) use ($draft): bool {
            $rows = data_get($r->data(), 'reply_markup.inline_keyboard', []);
            $row = collect($rows)->first(fn ($row) => data_get($row, '0.callback_data') === "draft:edit:{$draft->id}");

            return data_get($row, '1.callback_data') === "draft:reject:{$draft->id}"
                && ! str_contains(json_encode($rows), "draft:add:{$draft->id}");
        });
        Queue::assertNothingPushed();
    }

    public function test_paid_replacement_needs_consent_and_replaying_it_does_not_queue_twice(): void
    {
        $draft = $this->draft();
        $this->press("draft:restage:{$draft->id}");
        Queue::assertNothingPushed();
        $state = app(DraftTelegramInteractionState::class)->get('900', '123');
        $this->assertArrayHasKey('budget', $state);
        Http::assertSent(fn (Request $r) => str_contains((string) $r['text'], 'Настроенный бюджет'));
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        Queue::assertPushed(RestageDraftGalleryPhotos::class, 1);
        Queue::assertPushed(RestageDraftGalleryPhotos::class, fn ($job) => $job->draftId === $draft->id
            && $job->expectedDraftTelegramUpdateId === $draft->telegram_update_id);
        Queue::assertPushed(RestageDraftGalleryPhotos::class, function ($job) use ($draft) {
            $update = TelegramUpdate::query()->findOrFail($job->telegramUpdateId);

            return $update->text === "draft:restage:{$draft->id}"
                && data_get($update->payload, 'draft_action_confirmation.action') === "draft:restage:{$draft->id}";
        });
        $this->assertSame('pending_review', $draft->fresh()->status);
    }

    public function test_back_expiry_changed_generation_and_changed_budget_invalidate_consent(): void
    {
        foreach (['back', 'expiry', 'generation', 'budget', 'owner', 'chat', 'finalized'] as $scenario) {
            $draft = $this->draft();
            $this->press("draft:restage:{$draft->id}");
            $state = app(DraftTelegramInteractionState::class)->get('900', '123');
            match ($scenario) {
                'back' => $this->press("draft:review:{$draft->id}"),
                'expiry' => $this->travel(11)->minutes(),
                'generation' => $draft->update(['telegram_update_id' => $this->draft()->telegram_update_id]),
                'budget' => app(AiSettings::class)->saveMaxSearchCostUsd(9.87),
                'finalized' => $draft->update(['status' => 'rejected']),
                default => null,
            };
            $this->press("draft:confirm:{$draft->id}:{$state['token']}", user: $scenario === 'owner' ? 456 : 123,
                chat: $scenario === 'chat' ? 901 : 900);
            Queue::assertNothingPushed();
            $this->travelBack();
        }
    }

    public function test_refined_request_is_shown_then_queued_only_after_consent_without_changing_old_draft(): void
    {
        $draft = $this->draft();
        $original = $draft->only(['title', 'specifications', 'image_urls', 'telegram_update_id', 'status']);
        $this->press("draft:query:{$draft->id}");
        $this->message('Lenovo LOQ 15, белый, 32 GB, найди');
        Queue::assertNothingPushed();
        $state = app(DraftTelegramInteractionState::class)->get('900', '123');
        $this->assertSame('Lenovo LOQ 15, белый, 32 GB, найди', $state['query']);
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        $this->press("draft:confirm:{$draft->id}:{$state['token']}");
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
        Queue::assertPushed(ProcessTelegramMessage::class, function ($job) use ($draft): bool {
            $update = TelegramUpdate::query()->findOrFail($job->telegramUpdateId);

            return $job->freshConversation
                && $update->processed_at === null
                && str_contains($update->text, 'Lenovo LOQ 15, белый, 32 GB, найди')
                && data_get($update->payload, 'draft_revision.draft_id') === $draft->id;
        });
        $this->assertSame($original, $draft->fresh()->only(array_keys($original)));
    }

    public function test_cancel_refinement_does_not_swallow_the_next_normal_search(): void
    {
        $draft = $this->draft();
        $this->press("draft:query:{$draft->id}");
        $this->press("draft:input-cancel:{$draft->id}");
        $this->message('Найди другой SSD');
        Queue::assertPushed(ProcessTelegramMessage::class, fn ($job) => ! $job->freshConversation);
    }

    public function test_pending_text_input_does_not_turn_a_photo_into_an_unconfirmed_paid_search(): void
    {
        $draft = $this->draft();
        $this->press("draft:query:{$draft->id}");
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => $this->sequence, 'from' => ['id' => 123], 'chat' => ['id' => 900],
            'photo' => [['file_id' => 'test-photo', 'width' => 100, 'height' => 100]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        Queue::assertNothingPushed();
        $this->assertSame('new_search', app(DraftTelegramInteractionState::class)->get('900', '123')['kind']);
    }

    public function test_draft_undergoing_photo_work_cannot_be_reopened_for_approval_or_changed_twice(): void
    {
        $draft = $this->draft();
        Cache::put("draft-gallery-restage:{$draft->id}:queued", true, 600);
        $this->press("draft:open:{$draft->id}:{$draft->telegram_update_id}");
        $this->press("draft:add:{$draft->id}");
        $this->press("draft:source-retrain:{$draft->id}");
        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertNull(app(DraftTelegramInteractionState::class)->get('900', '123'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'));
        Queue::assertNothingPushed();
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => ++$this->sequence, 'telegram_user_id' => '123',
            'chat_id' => '900', 'text' => 'Lenovo белый', 'payload' => [], 'status' => 'completed', 'processed_at' => now()]);

        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai',
            'model' => 'test', 'status' => 'completed', 'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);

        return ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '123',
            'title' => 'Test laptop', 'status' => 'pending_review', 'primary_source_url' => 'https://shop.example/product',
            'specifications_reconciled_source_url' => 'https://shop.example/product', 'images_staged_at' => now(),
            'specifications' => [], 'sources' => [], 'image_urls' => [], 'telegram_review_chat_id' => '900', 'telegram_control_message_ids' => [700]]);
    }

    private function press(string $data, int $user = 123, int $chat = 900, int $message = 700): void
    {
        $id = ++$this->sequence;
        $this->postJson('/api/telegram/webhook', ['update_id' => $id, 'callback_query' => [
            'id' => "callback-{$id}", 'from' => ['id' => $user], 'data' => $data,
            'message' => ['message_id' => $message, 'chat' => ['id' => $chat]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function message(string $text, int $user = 123): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->sequence, 'message' => [
            'message_id' => $this->sequence, 'from' => ['id' => $user], 'chat' => ['id' => 900], 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
