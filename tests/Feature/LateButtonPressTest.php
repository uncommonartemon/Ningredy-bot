<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramInteractionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Answering a button press only stops its spinner. Telegram refuses that
 * answer once the press is a few seconds old - a webhook delivered late after
 * a restart - and the refusal used to throw out of the webhook before the
 * pressed action ran, so the button silently did nothing.
 */
class LateButtonPressTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_press_too_old_for_telegram_to_answer_still_runs_its_action(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response([
                'ok' => false, 'error_code' => 400,
                'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
            ], 400),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $draft = $this->unfinishedDraft();

        // A paid action asks first; then the confirmation runs it.
        $this->press(700001, "draft:continue-search:{$draft->id}");
        $token = app(DraftTelegramInteractionState::class)->get('98765', '12345')['token'] ?? null;
        $this->assertNotNull($token, 'the confirmation was still requested');
        $this->press(700002, "draft:confirm:{$draft->id}:{$token}");

        Queue::assertPushed(ContinueDraftGallerySearch::class, fn (ContinueDraftGallerySearch $job): bool => $job->draftId === $draft->id);
    }

    private function press(int $updateId, string $data): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'late-press-'.$updateId, 'from' => ['id' => 12345],
                'message' => ['message_id' => 5, 'chat' => ['id' => 98765]],
                'data' => $data,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function unfinishedDraft(): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 700000, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'payload' => [],
            'text' => 'Dell XPS 13 9340 ищи', 'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => 'fixture research', 'started_at' => now(), 'completed_at' => now(),
        ]);

        return ProductDraft::query()->create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'brand' => 'Dell', 'title' => 'Dell XPS 13 9340', 'model' => 'XPS 13 9340',
            'category' => 'laptops', 'product_type' => 'laptop', 'specifications' => [], 'sources' => [], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/dell-xps-13-9340',
            'gallery_status' => 'partial', 'gallery_search_stop_reason' => 'specifications_unreconciled',
            'images_staged_at' => now(),
        ]);
    }
}
