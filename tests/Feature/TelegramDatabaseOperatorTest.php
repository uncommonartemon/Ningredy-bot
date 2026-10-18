<?php

namespace Tests\Feature;

use App\Ai\Agents\ServerAssistantAgent;
use App\Ai\Tools\DescribeDatabase;
use App\Ai\Tools\ManageDatabase;
use App\Ai\Tools\QueryDatabase;
use App\Jobs\ProcessTelegramMessage;
use App\Models\AiOperation;
use App\Models\AppSetting;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramDatabaseOperatorTest extends TestCase
{
    use RefreshDatabase;

    private int $updateSequence = 81000;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345'],
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    public function test_agent_has_database_read_and_write_tools(): void
    {
        $agent = ServerAssistantAgent::make($this->update());
        $tools = collect($agent->tools())->map(fn ($tool) => $tool::class)->all();

        $this->assertContains(DescribeDatabase::class, $tools);
        $this->assertContains(QueryDatabase::class, $tools);
        $this->assertContains(ManageDatabase::class, $tools);
        $this->assertSame('required', $agent->toolChoice());
    }

    public function test_delete_all_needs_no_ids_and_job_sends_real_confirmation_even_if_model_omits_operation(): void
    {
        $draft = $this->draft();
        $update = $this->update();
        $prepared = $this->manage($update, $this->deleteAllDrafts());

        $this->assertTrue($prepared['ok']);
        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('ai_operations', [
            'id' => $prepared['operation_id'], 'status' => 'awaiting_confirmation', 'action' => 'database_changes',
        ]);

        ServerAssistantAgent::fake([[
            'response_type' => 'answer',
            'message' => 'Готово.',
            'draft_id' => null,
            'product_ids' => [],
            'operation_ids' => [],
        ]])->preventStrayPrompts();
        (new ProcessTelegramMessage($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "db:changes:confirm:{$prepared['operation_id']}"
            && data_get($request->data(), 'reply_markup.inline_keyboard.0.1.callback_data') === "db:changes:cancel:{$prepared['operation_id']}");
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
    }

    public function test_confirmation_deletes_all_owned_drafts_and_files_but_keeps_other_contexts(): void
    {
        $first = $this->draft(withImage: true);
        $second = $this->draft();
        $otherOperator = $this->draft(userId: '67890');
        $otherChat = $this->draft(chatId: '77777');
        $update = $this->update();
        $prepared = $this->manage($update, $this->deleteAllDrafts());

        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseMissing('product_drafts', ['id' => $first->id]);
        $this->assertDatabaseMissing('product_drafts', ['id' => $second->id]);
        $this->assertDatabaseHas('product_drafts', ['id' => $otherOperator->id]);
        $this->assertDatabaseHas('product_drafts', ['id' => $otherChat->id]);
        Storage::disk('public')->assertMissing("drafts/{$first->id}/label.webp");
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'completed']);
    }

    public function test_all_selection_is_not_limited_to_first_query_page(): void
    {
        foreach (range(1, 55) as $number) {
            $this->draft();
        }
        $foreign = $this->draft(userId: '67890');
        $update = $this->update();
        $page = json_decode((string) (new QueryDatabase($update))->handle(new Request([
            'table' => 'product_drafts', 'selection_json' => '{"all":true}', 'columns' => ['id', 'title'], 'limit' => 1,
        ])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(55, $page['total']);
        $this->assertCount(1, $page['rows']);
        $this->assertTrue($page['has_more']);
        $prepared = $this->manage($update, $this->deleteAllDrafts());
        $this->assertSame(55, $prepared['affected_count']);
        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertSame(0, ProductDraft::where('requested_by_telegram_user_id', '12345')->count());
        $this->assertDatabaseHas('product_drafts', ['id' => $foreign->id]);
    }

    public function test_long_mixed_batch_delivers_all_changed_values_before_final_confirmation_buttons(): void
    {
        $draft = $this->draft();
        $update = $this->update();
        $commands = [];
        foreach (range(1, 3) as $number) {
            $commands[] = ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'Preview RAM group '.$number]];
            $commands[] = ['action' => 'update', 'table' => 'product_drafts', 'selection' => ['ids' => [$draft->id]],
                'values' => ['description' => 'BEGIN-'.$number.'-'.str_repeat('Данные🙂', 800).'-END-'.$number]];
        }
        $commands = [...$commands, ...$this->deleteAllDrafts()];
        $prepared = $this->manage($update, $commands);
        $operation = AiOperation::findOrFail($prepared['operation_id']);
        $preview = app(TelegramDatabaseOperator::class)->preview($operation);
        $this->assertGreaterThan(10000, mb_strlen($preview));

        ServerAssistantAgent::fake([[
            'response_type' => 'database_confirmation', 'message' => 'Подтвердите операцию.',
            'draft_id' => null, 'product_ids' => [], 'operation_ids' => [],
        ]])->preventStrayPrompts();
        (new ProcessTelegramMessage($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        $messages = Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (array $exchange): array => $exchange[0]->data())->values();
        $this->assertGreaterThan(3, $messages->count());
        $delivered = '';
        foreach ($messages as $index => $message) {
            $this->assertLessThanOrEqual(4096, mb_strlen($message['text']));
            $delivered .= preg_replace('/^Операция #\d+ · часть \d+\/\d+\n\n/u', '', $message['text']);
            if ($index < $messages->count() - 1) {
                $this->assertArrayNotHasKey('reply_markup', $message);
            } else {
                $this->assertSame("db:changes:confirm:{$operation->id}", data_get($message, 'reply_markup.inline_keyboard.0.0.callback_data'));
            }
        }
        $this->assertSame($preview, $delivered);
        foreach (range(1, 3) as $number) {
            $this->assertStringContainsString('Preview RAM group '.$number, $delivered);
            $this->assertStringContainsString('-END-'.$number, $delivered);
        }
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id, 'description' => null]);
        $this->assertSame(0, AttributeGroup::where('name', 'like', 'Preview RAM group %')->count());
    }

    public function test_describe_database_exposes_column_relationships_and_read_only_restrictions_without_row_values(): void
    {
        $update = $this->update();
        AppSetting::put('private-test-key', 'secret-row-value-must-not-leak');
        $tool = new DescribeDatabase($update);
        $details = json_decode((string) $tool->handle(new Request(['table' => 'attribute_definitions'])), true, flags: JSON_THROW_ON_ERROR);
        $readOnly = json_decode((string) $tool->handle(new Request(['table' => 'ai_operations'])), true, flags: JSON_THROW_ON_ERROR);
        $overview = (string) $tool->handle(new Request);

        $this->assertSame(['attribute_definitions'], array_keys($details['tables']));
        $entry = $details['tables']['attribute_definitions'];
        $this->assertContains('attribute_group_id', $entry['writable_columns']);
        $this->assertSame('attribute_groups', data_get($entry, 'column_details.attribute_group_id.foreign_key.table'));
        $this->assertFalse(data_get($entry, 'column_details.id.writable'));
        $this->assertNotEmpty(data_get($entry, 'column_details.attribute_group_id.type'));
        $this->assertSame(['query'], $readOnly['tables']['ai_operations']['actions']);
        $this->assertSame([], $readOnly['tables']['ai_operations']['writable_columns']);
        $this->assertStringNotContainsString('secret-row-value-must-not-leak', $overview);
        $tables = json_decode($overview, true, flags: JSON_THROW_ON_ERROR)['tables'];
        $this->assertFalse($tables['app_settings']['readable']);
        $this->assertArrayNotHasKey('column_details', $tables['attribute_definitions']);
    }

    public function test_query_database_reads_long_unicode_cell_without_truncation_and_enforces_scope(): void
    {
        $draft = $this->draft();
        $description = 'BEGIN-'.str_repeat('Память🙂 – český text. ', 900).'-END';
        $draft->update(['description' => $description]);
        $update = $this->update();
        $tool = new QueryDatabase($update);
        $page = json_decode((string) $tool->handle(new Request([
            'table' => 'product_drafts', 'selection_json' => json_encode(['ids' => [$draft->id]], JSON_THROW_ON_ERROR), 'columns' => ['id', 'description'],
        ])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThan(mb_strlen($description), mb_strlen($page['rows'][0]['description']));

        $actual = '';
        $offset = 0;
        do {
            $chunk = json_decode((string) $tool->handle(new Request([
                'table' => 'product_drafts', 'record_id' => (string) $draft->id, 'value_column' => 'description',
                'value_offset' => $offset, 'value_limit' => 4000,
            ])), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('text', $chunk['encoding']);
            $this->assertSame(mb_strlen($description), $chunk['total_length']);
            $this->assertGreaterThan(0, $chunk['length']);
            $actual .= $chunk['text'];
            $offset += $chunk['length'];
        } while ($chunk['has_more']);

        $this->assertSame($description, $actual);
        $foreign = $this->draft(userId: '67890');
        $denied = json_decode((string) $tool->handle(new Request([
            'table' => 'product_drafts', 'record_id' => (string) $foreign->id, 'value_column' => 'description',
        ])), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($denied['ok']);
        $this->assertArrayNotHasKey('text', $denied);
    }

    public function test_cancel_then_old_confirm_keeps_drafts_and_photos(): void
    {
        $draft = $this->draft(withImage: true);
        $prepared = $this->manage($this->update(), $this->deleteAllDrafts());

        $this->postCallback("db:changes:cancel:{$prepared['operation_id']}");
        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        Storage::disk('public')->assertExists("drafts/{$draft->id}/label.webp");
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'cancelled']);
    }

    public function test_duplicate_confirmation_does_not_reexecute_or_delete_later_drafts(): void
    {
        $draft = $this->draft();
        $prepared = $this->manage($this->update(), $this->deleteAllDrafts());
        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");
        $laterDraft = $this->draft();

        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseMissing('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('product_drafts', ['id' => $laterDraft->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'completed']);
    }

    public function test_changed_row_invalidates_whole_confirmation_and_preserves_files(): void
    {
        $first = $this->draft(withImage: true);
        $second = $this->draft();
        $prepared = $this->manage($this->update(), $this->deleteAllDrafts());
        $second->update(['title' => 'Changed after preview']);

        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('product_drafts', ['id' => $first->id]);
        $this->assertDatabaseHas('product_drafts', ['id' => $second->id, 'title' => 'Changed after preview']);
        Storage::disk('public')->assertExists("drafts/{$first->id}/label.webp");
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'stale']);
    }

    public function test_confirmation_expiry_is_not_refreshed_by_repeated_tool_call(): void
    {
        $draft = $this->draft();
        $update = $this->update();
        $prepared = $this->manage($update, $this->deleteAllDrafts());
        $expires = data_get(AiOperation::findOrFail($prepared['operation_id'])->payload, 'expires_at');
        $this->travel(11)->minutes();

        $this->manage($update, $this->deleteAllDrafts());
        $this->assertSame($expires, data_get(AiOperation::findOrFail($prepared['operation_id'])->payload, 'expires_at'));
        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'expired']);
    }

    public function test_other_allowed_operator_or_chat_cannot_confirm_or_cancel_operation(): void
    {
        config(['services.telegram.allowed_user_ids' => ['12345', '67890']]);
        $draft = $this->draft();
        $prepared = $this->manage($this->update(), $this->deleteAllDrafts());

        $first = $this->postCallback("db:changes:confirm:{$prepared['operation_id']}", userId: 67890);
        $second = $this->postCallback("db:changes:cancel:{$prepared['operation_id']}", chatId: 77777);

        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'awaiting_confirmation']);
        foreach ([$first, $second] as $updateId) {
            $this->assertDatabaseHas('telegram_updates', ['update_id' => $updateId, 'status' => 'rejected']);
        }
    }

    public function test_cancelled_source_request_cannot_be_confirmed_later(): void
    {
        $draft = $this->draft();
        $update = $this->update();
        $prepared = $this->manage($update, $this->deleteAllDrafts());
        $update->update(['cancel_requested_at' => now()]);

        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertNotSame('completed', AiOperation::findOrFail($prepared['operation_id'])->status);
    }

    public function test_mixed_create_and_delete_batch_runs_only_after_confirmation(): void
    {
        $draft = $this->draft();
        $prepared = $this->manage($this->update(), [
            ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'RAM operator test']],
            ...$this->deleteAllDrafts(),
        ]);

        $this->assertTrue($prepared['confirmation_required']);
        $this->assertDatabaseMissing('attribute_groups', ['name' => 'RAM operator test']);
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseHas('attribute_groups', ['name' => 'RAM operator test', 'highlight_in_filters' => true]);
        $this->assertDatabaseMissing('product_drafts', ['id' => $draft->id]);
    }

    public function test_stale_delete_rolls_back_other_changes_in_mixed_batch(): void
    {
        $draft = $this->draft(withImage: true);
        $prepared = $this->manage($this->update(), [
            ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'Must not exist']],
            ...$this->deleteAllDrafts(),
        ]);
        $draft->update(['description' => 'New manual edit']);

        $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertDatabaseMissing('attribute_groups', ['name' => 'Must not exist']);
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id, 'description' => 'New manual edit']);
        Storage::disk('public')->assertExists("drafts/{$draft->id}/label.webp");
    }

    public function test_batch_creates_group_and_assigns_attribute_id_range_once(): void
    {
        $definitions = collect(range(1, 4))->map(fn (int $number) => AttributeDefinition::create([
            'key' => 'operator-memory-'.$number,
            'label' => 'Operator memory '.$number,
            'data_type' => 'text',
        ]));
        $commands = [
            ['action' => 'create', 'table' => 'attribute_groups', 'ref' => 'ram', 'values' => ['name' => 'RAM batch test']],
            ['action' => 'update', 'table' => 'attribute_definitions',
                'selection' => ['id_from' => $definitions[1]->id, 'id_to' => $definitions[2]->id],
                'values' => ['attribute_group_id' => ['$ref' => 'ram.id']]],
        ];
        $update = $this->update();

        $first = $this->manage($update, $commands);
        $second = $this->manage($update, $commands);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertSame(1, AttributeGroup::query()->where('name', 'RAM batch test')->count());
        $group = AttributeGroup::query()->where('name', 'RAM batch test')->firstOrFail();
        $this->assertSame([$definitions[1]->id, $definitions[2]->id], $group->attributeDefinitions()->orderBy('id')->pluck('id')->all());
        $this->assertNull($definitions[0]->fresh()->attribute_group_id);
        $this->assertNull($definitions[3]->fresh()->attribute_group_id);
    }

    public function test_malformed_commands_and_system_table_writes_do_not_mutate_catalog(): void
    {
        $update = $this->update();
        $before = AttributeGroup::count();
        $malformed = json_decode((string) (new ManageDatabase($update))->handle(new Request(['commands_json' => '{broken'])), true, flags: JSON_THROW_ON_ERROR);
        $blocked = $this->manage($update, [['action' => 'update', 'table' => 'app_settings', 'selection' => ['all' => true], 'values' => ['value' => 'compromised']]]);

        $this->assertFalse($malformed['ok']);
        $this->assertFalse($blocked['ok']);
        $this->assertSame($before, AttributeGroup::count());
        $this->assertDatabaseMissing('ai_operations', ['status' => 'completed', 'action' => 'database_changes']);
    }

    public function test_database_allowlist_overrides_environment_for_database_tools(): void
    {
        config(['services.telegram.allowed_user_ids' => ['99999']]);
        AppSetting::put(AppSetting::TELEGRAM_ALLOWED_USER_IDS, "12345\n67890");

        $allowed = $this->manage($this->update(), [
            ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'Saved allowlist allowed']],
        ]);
        $denied = $this->manage($this->update('99999'), [
            ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'Removed operator forbidden']],
        ]);

        $this->assertTrue($allowed['ok']);
        $this->assertFalse($denied['ok']);
        $this->assertDatabaseHas('attribute_groups', ['name' => 'Saved allowlist allowed']);
        $this->assertDatabaseMissing('attribute_groups', ['name' => 'Removed operator forbidden']);
    }

    public function test_operator_removed_from_allowlist_cannot_read_or_confirm_pending_changes(): void
    {
        $draft = $this->draft();
        $update = $this->update();
        $prepared = $this->manage($update, $this->deleteAllDrafts());
        AppSetting::put(AppSetting::TELEGRAM_ALLOWED_USER_IDS, '67890');

        $query = json_decode((string) (new QueryDatabase($update))->handle(new Request([
            'table' => 'product_drafts', 'selection_json' => '{"all":true}',
        ])), true, flags: JSON_THROW_ON_ERROR);
        $callbackUpdateId = $this->postCallback("db:changes:confirm:{$prepared['operation_id']}");

        $this->assertFalse($query['ok']);
        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $prepared['operation_id'], 'status' => 'awaiting_confirmation']);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => $callbackUpdateId, 'status' => 'rejected']);
    }

    #[DataProvider('conversationResetCommands')]
    public function test_new_and_reset_cancel_only_current_operator_and_chat_confirmations(string $command): void
    {
        config(['services.telegram.allowed_user_ids' => ['12345', '67890']]);
        $draft = $this->draft();
        $this->draft(userId: '67890');
        $this->draft(chatId: '77777');
        $own = $this->manage($this->update(), $this->deleteAllDrafts());
        $otherUser = $this->manage($this->update('67890'), $this->deleteAllDrafts());
        $otherChat = $this->manage($this->update(chatId: '77777'), $this->deleteAllDrafts());
        $sequence = ++$this->updateSequence;

        $this->postJson('/api/telegram/webhook', [
            'update_id' => $sequence,
            'message' => [
                'message_id' => 90002,
                'from' => ['id' => 12345],
                'chat' => ['id' => 98765],
                'text' => $command,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();
        $this->postCallback("db:changes:confirm:{$own['operation_id']}");

        $this->assertDatabaseHas('product_drafts', ['id' => $draft->id]);
        $this->assertDatabaseHas('ai_operations', ['id' => $own['operation_id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('ai_operations', ['id' => $otherUser['operation_id'], 'status' => 'awaiting_confirmation']);
        $this->assertDatabaseHas('ai_operations', ['id' => $otherChat['operation_id'], 'status' => 'awaiting_confirmation']);
    }

    public static function conversationResetCommands(): array
    {
        return [['/new'], ['/reset']];
    }

    /** @return list<array<string, mixed>> */
    public function test_empty_delete_is_audited_without_confirmation_and_does_not_adopt_later_rows(): void
    {
        $update = $this->update();
        $empty = $this->manage($update, $this->deleteAllDrafts());

        $this->assertTrue($empty['ok']);
        $this->assertFalse($empty['confirmation_required']);
        $this->assertSame(0, $empty['affected_count']);
        $this->assertSame('completed', $empty['status']);
        $this->assertNotEmpty($empty['message']);
        $later = $this->draft();

        $repeated = $this->manage($update, $this->deleteAllDrafts());
        $this->assertSame($empty['operation_id'], $repeated['operation_id']);
        $this->assertFalse($repeated['confirmation_required']);
        $this->postCallback("db:changes:confirm:{$empty['operation_id']}");
        $this->assertDatabaseHas('product_drafts', ['id' => $later->id]);
    }

    private function deleteAllDrafts(): array
    {
        return [['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['all' => true]]];
    }

    private function manage(TelegramUpdate $update, array $commands): array
    {
        return json_decode((string) (new ManageDatabase($update))->handle(new Request([
            'commands_json' => json_encode($commands, JSON_THROW_ON_ERROR),
        ])), true, flags: JSON_THROW_ON_ERROR);
    }

    private function update(string $userId = '12345', string $chatId = '98765', bool $completed = false): TelegramUpdate
    {
        $sequence = ++$this->updateSequence;

        return TelegramUpdate::create([
            'update_id' => $sequence,
            'telegram_user_id' => $userId,
            'chat_id' => $chatId,
            'message_id' => $sequence,
            'text' => 'Удали все черновики',
            'payload' => ['update_id' => $sequence],
            'status' => $completed ? 'completed' : 'received',
            'processed_at' => $completed ? now() : null,
        ]);
    }

    private function draft(string $userId = '12345', string $chatId = '98765', bool $withImage = false): ProductDraft
    {
        $update = $this->update($userId, $chatId, completed: true);
        $run = $update->aiRuns()->create(['provider' => 'openai', 'model' => 'test', 'prompt' => 'Test fixture', 'status' => 'completed', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => $userId,
            'status' => 'pending_review',
            'title' => 'Operator test laptop '.$update->id,
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
        ]);
        if ($withImage) {
            $path = "drafts/{$draft->id}/label.webp";
            Storage::disk('public')->put($path, 'test-image-bytes');
            $draft->media()->create(['disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', 'test-image-bytes'), 'role' => 'primary', 'is_primary' => true]);
        }

        return $draft;
    }

    private function postCallback(string $data, int $userId = 12345, int $chatId = 98765): int
    {
        $sequence = ++$this->updateSequence;
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $sequence,
            'callback_query' => [
                'id' => 'operator-callback-'.$sequence,
                'from' => ['id' => $userId, 'username' => 'admin'],
                'data' => $data,
                'message' => ['message_id' => 90001, 'chat' => ['id' => $chatId]],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'])->assertOk();

        return $sequence;
    }
}
