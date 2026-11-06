<?php

namespace Tests\Feature;

use App\Ai\Tools\EditProductDraft;
use App\Ai\Tools\ShowProductDraft;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

/**
 * Module 72: «дай черновик 213» is one draft, not the list, and a correction in
 * the operator's words becomes the draft's own signed edit with its confirmation.
 */
class TelegramDraftChatToolsTest extends TestCase
{
    use RefreshDatabase;

    private int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 77]]));
        Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true, 'product_type_affinity' => 'laptop']);
    }

    public function test_show_sends_the_card_of_a_pending_draft_and_answers_with_its_data(): void
    {
        $draft = $this->draft();
        $this->mock(DraftTelegramPresenter::class, function ($mock) use ($draft): void {
            $mock->shouldReceive('unreconciledFields')->andReturn([['key' => 'battery', 'name' => 'Батарея']]);
            $mock->shouldReceive('clearForSearchContinuation')->once();
            $mock->shouldReceive('sendReview')->once()
                ->withArgs(fn ($telegram, string $chat, ProductDraft $sent): bool => $chat === '98765' && $sent->id === $draft->id);
        });

        $data = json_decode((new ShowProductDraft($this->ask('дай черновик '.$draft->id)))->handle(new ToolRequest(['draft_id' => $draft->id])), true);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['card_sent']);
        $this->assertSame('pending_review', $data['status']);
        $this->assertSame('ожидает решения', $data['status_text']);
        $this->assertSame('Fixture laptop', $data['title']);
        $this->assertSame('Find fixture laptop', $data['request']);
        $this->assertSame([['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB']], $data['specifications']);
        $this->assertFalse($data['specifications_reconciled']);
        $this->assertSame(['Батарея'], $data['unreconciled_fields']);
        $this->assertSame(1, $data['photos']['count']);
        $this->assertSame(1, $data['photos']['verified']);
        $this->assertSame(['https://shop.example/exact'], $data['sources']);
    }

    public function test_show_refuses_another_operators_draft_and_sends_no_card_for_a_processed_one(): void
    {
        $draft = $this->draft();
        $stranger = TelegramUpdate::create(['update_id' => 920002, 'chat_id' => '555', 'telegram_user_id' => '777',
            'text' => 'покажи черновик', 'payload' => [], 'status' => 'processing']);
        $this->assertFalse(json_decode((new ShowProductDraft($stranger))->handle(new ToolRequest(['draft_id' => $draft->id])), true)['ok']);
        $this->assertFalse(json_decode((new ShowProductDraft($this->ask('покажи черновик')))->handle(new ToolRequest(['draft_id' => 999])), true)['ok']);

        $draft->update(['status' => 'approved']);
        $data = json_decode((new ShowProductDraft($this->ask('дай черновик')))->handle(new ToolRequest(['draft_id' => $draft->id])), true);

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['card_sent']);
        $this->assertSame('добавлен в каталог', $data['status_text']);
        Http::assertNothingSent();
    }

    public function test_show_does_not_resend_a_card_while_a_job_works_on_the_draft(): void
    {
        $draft = $this->draft();
        Cache::put("draft-photo-actions:{$draft->id}:queued", true, 60);

        $data = json_decode((new ShowProductDraft($this->ask('покажи черновик')))->handle(new ToolRequest(['draft_id' => $draft->id])), true);

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['card_sent']);
        $this->assertArrayHasKey('busy', $data);
        Http::assertNothingSent();
    }

    public function test_edit_prepares_the_drafts_own_signed_change_and_asks_for_confirmation(): void
    {
        $draft = $this->draft();

        $data = json_decode((new EditProductDraft($this->ask('в черновике '.$draft->id.' цвет: чёрный')))
            ->handle(new ToolRequest(['draft_id' => $draft->id, 'instruction' => 'цвет: чёрный'])), true);

        $this->assertTrue($data['ok'], json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertSame('confirmation_required', $data['status']);
        $this->assertStringContainsString('чёрный', $data['preview']);
        $state = app(DraftTelegramInteractionState::class)->get('98765', '12345');
        $this->assertSame('confirmation', $state['kind']);
        $this->assertSame("draft:apply-edit:{$draft->id}", data_get($state, 'callback'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Применить изменения к черновику?'));
        $this->assertFalse(Cache::has("draft-fields:{$draft->id}:queued"));
        // Nothing changed yet: the button applies the signed proposal.
        $this->assertNull($draft->fresh()->color);
    }

    public function test_edit_refuses_a_processed_draft_an_empty_instruction_and_a_busy_draft(): void
    {
        $draft = $this->draft();
        $asked = $this->ask('поправь черновик');

        $this->assertFalse(json_decode((new EditProductDraft($asked))->handle(new ToolRequest(['draft_id' => $draft->id, 'instruction' => '  '])), true)['ok']);
        Cache::put("draft-enrich:{$draft->id}:queued", true, 60);
        $this->assertFalse(json_decode((new EditProductDraft($asked))->handle(new ToolRequest(['draft_id' => $draft->id, 'instruction' => 'цвет: чёрный'])), true)['ok']);
        Cache::forget("draft-enrich:{$draft->id}:queued");
        $draft->update(['status' => 'approved']);
        $refused = json_decode((new EditProductDraft($asked))->handle(new ToolRequest(['draft_id' => $draft->id, 'instruction' => 'цвет: чёрный'])), true);

        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('approved', $refused['error']);
        Http::assertNothingSent();
    }

    private function draft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => 910001, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => 'Find fixture laptop', 'payload' => [], 'status' => 'completed']);
        $run = $update->aiRuns()->create(['provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture',
            'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'title' => 'Fixture laptop', 'brand' => 'Dell', 'model' => 'Latitude 5450', 'description' => 'A fixture.',
            'status' => 'pending_review', 'category' => 'laptops', 'product_type' => 'laptop', 'condition' => 'new',
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB']], 'sources' => [], 'image_urls' => [],
            'primary_source_url' => 'https://shop.example/exact', 'gallery_status' => 'complete']);
        Storage::disk('public')->put("drafts/{$draft->id}/1.jpg", 'fake-image');
        $draft->media()->create(['disk' => 'public', 'path' => "drafts/{$draft->id}/1.jpg", 'checksum' => hash('sha256', 'fake-image'),
            'width' => 1000, 'height' => 800, 'verification_status' => 'verified', 'is_primary' => true, 'sort_order' => 1]);

        return $draft;
    }

    private function ask(string $text): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => 920100 + (++$this->asked), 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'text' => $text, 'payload' => [], 'status' => 'processing']);
    }
}
