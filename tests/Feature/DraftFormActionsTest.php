<?php

namespace Tests\Feature;

use App\Jobs\FindDraftFormFields;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Catalog\CategoryStandard;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Decision of 2026-11-13: the form report of a draft has buttons in Telegram - look for a field
 * not found, write its value, pick one of the example's variants, or keep an inexact value.
 */
class DraftFormActionsTest extends TestCase
{
    use RefreshDatabase;

    private ProductDraft $draft;

    private AttributeDefinition $buffering;

    private AttributeDefinition $module;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake([FindDraftFormFields::class]);
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]])]);
        $ram = Category::query()->where('slug', 'ram')->firstOrFail();
        $this->buffering = AttributeDefinition::query()->create(['key' => 'buffering', 'label' => 'Буферизация', 'data_type' => 'text',
            'translations' => ['en' => 'Buffering', 'ru' => 'Буферизация']]);
        $this->module = AttributeDefinition::query()->firstOrCreate(['key' => 'module_type'], ['label' => 'Module type', 'data_type' => 'text']);
        app(CategoryStandard::class)->syncForm($ram, [
            ['attribute_definition_id' => $this->module->id, 'example' => 'UDIMM / RDIMM / SO-DIMM', 'is_required' => true],
            ['attribute_definition_id' => $this->buffering->id, 'example' => 'Registered / Unbuffered', 'is_required' => true],
        ]);
        $this->draft = $this->draft([['key' => 'module_type', 'name' => 'Module type', 'value' => 'Supported']]);
    }

    public function test_the_card_has_the_form_button_and_the_menu_has_a_row_per_field(): void
    {
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '98765', $this->draft);
        Http::assertSent(fn (ClientRequest $request): bool => collect((array) data_get($request->data(), 'reply_markup.inline_keyboard', []))
            ->flatten(1)->contains(fn (array $button): bool => ($button['callback_data'] ?? '') === "draft:form:{$this->draft->id}"
                && str_contains($button['text'], '1 ❓') && str_contains($button['text'], '1 ⚠️')));

        $this->press("draft:form:{$this->draft->id}", 6101);

        Http::assertSent(function (ClientRequest $request): bool {
            $data = collect((array) data_get($request->data(), 'reply_markup.inline_keyboard', []))->flatten(1)->pluck('callback_data')->all();

            return str_contains((string) ($request['text'] ?? ''), 'что осталось')
                && in_array("df:all:{$this->draft->id}", $data, true)
                && in_array("df:find:{$this->draft->id}:{$this->buffering->id}", $data, true)
                && in_array("df:write:{$this->draft->id}:{$this->buffering->id}", $data, true)
                && in_array("df:set:{$this->draft->id}:{$this->module->id}:1", $data, true)
                && in_array("df:ok:{$this->draft->id}:{$this->module->id}", $data, true);
        });
    }

    public function test_a_written_value_and_a_picked_variant_fill_the_form(): void
    {
        $this->press("df:write:{$this->draft->id}:{$this->buffering->id}", 6102);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Пришлите значение для «Буферизация»'));
        $this->say('Registered', 6103);
        $this->press("df:set:{$this->draft->id}:{$this->module->id}:1", 6104);

        $statuses = collect(app(CategoryStandard::class)->statuses($this->draft->fresh()))->keyBy('key');
        $this->assertSame('filled', $statuses['buffering']['status']);
        $this->assertSame('Registered', $statuses['buffering']['value']);
        $this->assertSame('filled', $statuses['module_type']['status']);
        $this->assertSame('RDIMM', $statuses['module_type']['value']);
        $written = collect($this->draft->fresh()->specifications)->firstWhere('key', 'buffering');
        $this->assertTrue($written['operator_stated']);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains((string) ($request['text'] ?? ''), 'Форма «')
            && str_contains((string) $request['text'], 'заполнена'));
    }

    public function test_an_inexact_value_is_kept_and_a_field_is_looked_for(): void
    {
        $this->press("df:ok:{$this->draft->id}:{$this->module->id}", 6105);
        $this->assertSame('filled', collect(app(CategoryStandard::class)->statuses($this->draft->fresh()))->firstWhere('key', 'module_type')['status']);

        $this->press("df:find:{$this->draft->id}:{$this->buffering->id}", 6106);
        $this->press("df:all:{$this->draft->id}", 6107);

        Queue::assertPushed(FindDraftFormFields::class, fn (FindDraftFormFields $job): bool => $job->keys === ['buffering']);
        Queue::assertPushed(FindDraftFormFields::class, fn (FindDraftFormFields $job): bool => $job->keys === []);
    }

    public function test_a_command_ends_the_wait_for_a_value(): void
    {
        $this->press("df:write:{$this->draft->id}:{$this->buffering->id}", 6108);
        $this->say('/drafts', 6109);
        $this->say('Registered', 6110);

        $this->assertSame('missing', collect(app(CategoryStandard::class)->statuses($this->draft->fresh()))->firstWhere('key', 'buffering')['status']);
    }

    private function draft(array $specifications): ProductDraft
    {
        $update = TelegramUpdate::query()->create(['update_id' => 6100, 'telegram_user_id' => '12345', 'chat_id' => '98765', 'message_id' => 1,
            'text' => 'Module', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'openai', 'model' => 'test', 'status' => 'completed',
            'prompt' => 'Find', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'status' => 'pending_review', 'title' => 'Fixture RDIMM', 'brand' => 'Fixture', 'model' => 'FX-1', 'description' => 'A module.',
            'product_type' => 'component', 'category' => 'ram', 'confidence' => 0.9, 'image_urls' => [], 'images_staged_at' => now(),
            'specifications' => array_map(fn (array $spec): array => [...$spec, 'source_url' => 'https://source.example/m', 'source_excerpt' => $spec['value']], $specifications),
            'sources' => [['title' => 'Fixture', 'url' => 'https://source.example/m']], 'primary_source_url' => 'https://source.example/m',
            'specifications_reconciled_source_url' => 'https://source.example/m']);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/'.$draft->id.'/a.webp', 'source_url' => 'https://source.example/a.webp',
            'role' => 'primary', 'mime_type' => 'image/webp', 'width' => 2000, 'height' => 2000, 'file_size' => 1, 'checksum' => hash('sha256', 'form'),
            'verification_status' => 'verified', 'sort_order' => 0, 'is_primary' => true]);

        return $draft;
    }

    private function press(string $data, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'callback_query' => [
            'id' => 'callback-'.$updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'data' => $data,
            'message' => ['message_id' => 901, 'chat' => ['id' => 98765]],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }

    private function say(string $text, int $updateId): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => $updateId, 'message' => [
            'message_id' => $updateId, 'from' => ['id' => 12345, 'username' => 'admin'], 'chat' => ['id' => 98765], 'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
    }
}
