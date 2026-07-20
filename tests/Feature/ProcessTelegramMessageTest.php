<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductResearchAgent;
use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\ResearchProduct;
use App\Jobs\ProcessTelegramMessage;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Products\ProductImageResolver;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ProcessTelegramMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_assistant_replies_to_telegram_and_records_the_run(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        ServerAssistantAgent::fake([[
            'response_type' => 'answer',
            'message' => 'Сервер работает нормально.',
            'draft_id' => null,
            'product_ids' => [],
            'operation_ids' => [],
        ]]);
        $update = $this->update();

        (new ProcessTelegramMessage($update->id))->handle(
            app(TelegramClient::class),
            app(AiErrorPresenter::class),
        );

        $this->assertDatabaseHas('ai_runs', ['telegram_update_id' => $update->id, 'status' => 'completed']);
        $this->assertDatabaseHas('telegram_updates', ['id' => $update->id, 'status' => 'completed']);
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === '98765'
            && $request['text'] === 'Сервер работает нормально.');
    }

    public function test_research_tool_creates_an_audited_pending_draft(): void
    {
        ProductResearchAgent::fake([[
            'status' => 'found',
            'clarification_question' => null,
            'title' => 'Lenovo Legion 5 16IRX9',
            'brand' => 'Lenovo',
            'model' => 'Legion 5 16IRX9',
            'color' => 'Luna Grey',
            'description' => 'Игровой ноутбук.',
            'specifications' => [['key' => 'ram', 'name' => 'RAM', 'value' => '32 GB']],
            'sources' => [['title' => 'Lenovo', 'url' => 'https://www.lenovo.com/example']],
            'image_urls' => ['https://example.com/legion.jpg'],
            'confidence' => 0.95,
        ]]);
        $update = $this->update();

        $resolver = $this->mock(ProductImageResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([]);
        $result = json_decode((new ResearchProduct($update, $resolver))->handle(new Request([
            'query' => 'Lenovo Legion 5 32 GB',
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ok']);
        $this->assertSame('found', $result['status']);
        $this->assertDatabaseHas('product_drafts', [
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo Legion 5 16IRX9',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('ai_operations', [
            'telegram_update_id' => $update->id,
            'action' => 'create_product_draft',
            'status' => 'completed',
        ]);
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'Проверь состояние сервера',
            'payload' => ['update_id' => 2001],
            'status' => 'received',
        ]);
    }
}
