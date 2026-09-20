<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class TelegramRefinedSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_refined_search_does_not_continue_the_old_conversation_or_present_its_draft(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'app.boot_id' => 'current']);
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        User::factory()->create();
        $source = TelegramUpdate::query()->create(['update_id' => 970001, 'chat_id' => '900',
            'telegram_user_id' => '123', 'text' => 'old query', 'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
        $run = AiRun::query()->create(['telegram_update_id' => $source->id, 'provider' => 'openai',
            'model' => 'test', 'status' => 'completed', 'prompt' => 'old query', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $source->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '123', 'title' => 'Old laptop', 'status' => 'pending_review',
            'specifications' => [], 'sources' => [], 'image_urls' => ['https://shop.example/old.jpg']]);
        $update = TelegramUpdate::query()->create(['update_id' => 970002, 'chat_id' => '900',
            'telegram_user_id' => '123', 'text' => 'Найди Lenovo, белый, 32 GB', 'payload' => [], 'status' => 'received']);
        TelegramChatState::query()->create(['chat_id' => '900', 'telegram_user_id' => '123',
            'conversation_id' => 'old-conversation', 'boot_id' => 'current']);
        ServerAssistantAgent::fake([['response_type' => 'answer', 'message' => 'Нового результата нет.',
            'draft_id' => $draft->id, 'product_ids' => [], 'operation_ids' => []]]);

        (new ProcessTelegramMessage($update->id, freshConversation: true))->handle(
            app(TelegramClient::class), app(AiErrorPresenter::class));

        ServerAssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('белый, 32 GB')
            && $prompt->agent->currentConversation() !== 'old-conversation');
        Http::assertNotSent(fn (Request $request) => str_contains((string) $request['text'], "Черновик #{$draft->id}")
            || str_contains(json_encode($request->data()), "draft:add:{$draft->id}"));
        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertSame(['https://shop.example/old.jpg'], $draft->fresh()->image_urls);
        $this->assertDatabaseCount('product_drafts', 1);
        $this->assertSame('completed', $update->fresh()->status);
    }

    public function test_jobs_serialized_before_the_new_option_remain_compatible(): void
    {
        $job = new ProcessTelegramMessage(42);
        unset($job->freshConversation);
        $restored = unserialize(serialize($job));
        $this->assertFalse($restored->freshConversation);
    }
}
