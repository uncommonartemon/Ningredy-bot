<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramDraftEditAgent;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramDraftEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramDraftEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        TelegramDraftEditAgent::fake()->preventStrayPrompts();
        Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true]);
    }

    public function test_simple_price_creates_a_preview_without_ai_and_only_confirmation_applies_it(): void
    {
        [$draft, $message] = $this->draft();
        $mediaIds = $draft->media()->pluck('id')->all();
        $editor = app(TelegramDraftEditor::class);

        $proposal = $editor->propose($draft, $message, 'цена: 12 500,50 CZK');

        $this->assertSame('10000.00', $draft->fresh()->manualIntake()['commercial']['price_amount']);
        $this->assertSame('12500.50', $proposal['patch']['commercial']['price_amount']);
        $this->assertStringContainsString('10000.00 → 12500.50', $proposal['preview']);
        $this->assertDatabaseCount('ai_runs', 1);
        $saved = $editor->apply($draft, $message, $proposal);
        $this->assertSame('12500.50', $saved->manualIntake()['commercial']['price_amount']);
        $this->assertSame('CZK', $saved->manualIntake()['commercial']['currency']);
        $this->assertSame('ready', $saved->manualIntake()['status']);
        $this->assertSame($mediaIds, $saved->media()->pluck('id')->all());
        $this->assertSame('pending_review', $saved->status);
        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }

    public function test_natural_language_changes_only_the_requested_specification_and_records_usage(): void
    {
        [$draft, $message] = $this->draft();
        TelegramDraftEditAgent::fake(fn (): array => [
            'summary' => 'Указанная память — 32 GB.',
            'changes' => [['field' => 'specification', 'name' => 'Memory', 'value' => '32 GB', 'evidence_quote' => 'память теперь 32 GB']],
        ])->preventStrayPrompts();
        $editor = app(TelegramDraftEditor::class);

        $proposal = $editor->propose($draft, $message, 'Исправь: память теперь 32 GB');

        $this->assertSame('16 GB', $draft->fresh()->specifications[0]['value']);
        $this->assertSame('completed', AiRun::query()->latest('id')->first()->status);
        $this->assertNotNull(AiRun::query()->latest('id')->first()->usage);
        $saved = $editor->apply($draft, $message, $proposal);
        $this->assertSame('memory', $saved->specifications[0]['key']);
        $this->assertSame('32 GB', $saved->specifications[0]['value']);
        $this->assertSame('512 GB', $saved->specifications[1]['value']);
        $this->assertNull($saved->specifications[0]['source_url']);
        $this->assertSame('память теперь 32 GB', $saved->specifications[0]['source_excerpt']);
    }

    public function test_invented_evidence_is_rejected_and_the_paid_response_stays_audited(): void
    {
        [$draft, $message] = $this->draft();
        TelegramDraftEditAgent::fake(fn (): array => [
            'summary' => 'Изменить модель.',
            'changes' => [['field' => 'model', 'name' => null, 'value' => 'Different', 'evidence_quote' => 'несуществующая цитата']],
        ])->preventStrayPrompts();

        try {
            app(TelegramDraftEditor::class)->propose($draft, $message, 'Поменяй описание');
            $this->fail('Missing evidence must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('без цитаты', $exception->getMessage());
        }
        $this->assertSame('Test 14', $draft->fresh()->model);
        $this->assertSame('failed', AiRun::query()->latest('id')->first()->status);
        $this->assertNotNull(AiRun::query()->latest('id')->first()->usage);
    }

    public function test_foreign_operator_cannot_prepare_or_apply_an_edit(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $message->update(['telegram_user_id' => 'someone-else']);

        $this->expectException(RuntimeException::class);
        $editor->apply($draft, $message->fresh(), $proposal);
    }

    public function test_a_changed_photo_invalidates_the_preview(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $draft->media()->first()->update(['verification_status' => 'pending']);

        $this->expectException(RuntimeException::class);
        $editor->apply($draft, $message, $proposal);
    }

    public function test_an_expired_preview_cannot_be_applied(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $this->travel(11)->minutes();

        $this->expectException(RuntimeException::class);
        $editor->apply($draft, $message, $proposal);
    }

    public function test_a_changed_generation_invalidates_the_preview(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $next = TelegramUpdate::query()->create([
            'update_id' => 99003, 'chat_id' => 'chat-1', 'telegram_user_id' => 'user-1', 'status' => 'received', 'payload' => [],
        ]);
        $draft->update(['telegram_update_id' => $next->id]);

        $this->expectException(RuntimeException::class);
        $editor->apply($draft, $message, $proposal);
    }

    public function test_only_translations_of_changed_values_are_removed(): void
    {
        [$draft, $message] = $this->draft();
        $draft->update(['translations' => ['cs' => [
            'title' => 'Notebook', 'description' => 'Popis', 'specifications' => [
                ['key' => 'memory', 'name' => 'Paměť', 'value' => '16 GB'],
                ['key' => 'storage', 'name' => 'Úložiště', 'value' => '512 GB'],
            ],
        ]]]);
        $editor = app(TelegramDraftEditor::class);
        $specifications = $draft->specifications;
        $specifications[0]['value'] = '32 GB';
        $proposal = $editor->proposalFor($draft, $message, ['fields' => ['specifications' => $specifications]], 'Память 32 GB');

        $saved = $editor->apply($draft, $message, $proposal);

        $this->assertSame('Notebook', $saved->translations['cs']['title']);
        $this->assertSame('Popis', $saved->translations['cs']['description']);
        $this->assertSame([['key' => 'storage', 'name' => 'Úložiště', 'value' => '512 GB']], $saved->translations['cs']['specifications']);
    }

    public function test_the_draft_cannot_be_changed_while_a_parser_prepares_the_preview(): void
    {
        [$draft, $message] = $this->draft();
        TelegramDraftEditAgent::fake(function () use ($draft): array {
            $draft->update(['description' => 'Edited concurrently']);

            return ['summary' => 'Изменить память.', 'changes' => [['field' => 'specification', 'name' => 'Memory',
                'value' => '32 GB', 'evidence_quote' => 'память 32 GB']]];
        })->preventStrayPrompts();

        try {
            app(TelegramDraftEditor::class)->propose($draft, $message, 'Исправь память 32 GB');
            $this->fail('A concurrent draft change must invalidate a parser response.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('изменился', $exception->getMessage());
        }
        $this->assertSame('16 GB', $draft->fresh()->specifications[0]['value']);
        $this->assertSame('failed', AiRun::query()->latest('id')->first()->status);
        $this->assertNotNull(AiRun::query()->latest('id')->first()->usage);
    }

    public function test_every_changed_value_is_kept_in_long_preview_chunks(): void
    {
        [$draft, $message] = $this->draft();
        $value = str_repeat('Long new description. ', 220);
        $proposal = app(TelegramDraftEditor::class)->propose($draft, $message, 'описание: '.$value);

        $this->assertGreaterThan(1, count($proposal['preview_messages']));
        foreach ($proposal['preview_messages'] as $chunk) {
            $this->assertLessThanOrEqual(3500, mb_strlen($chunk));
        }
        $this->assertStringContainsString(trim($value), implode('', $proposal['preview_messages']));
    }

    public function test_edited_researched_fields_are_not_reconciled_automatically(): void
    {
        [$draft, $message] = $this->draft(manual: false);
        ProductDraft::withReconciliationWrite(fn () => $draft->update([
            'primary_source_url' => 'https://example.com/product', 'specifications_reconciled_source_url' => 'https://example.com/product',
        ]));
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'бренд: Other brand');

        $saved = $editor->apply($draft, $message, $proposal);

        $this->assertNull($saved->specifications_reconciled_source_url);
        $this->assertTrue($saved->reconciliationPending());
        $this->assertSame('pending_review', $saved->status);
    }

    public function test_server_enrichment_proposal_keeps_citations_and_does_not_buy_a_second_agent_call(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $specifications = [...$draft->specifications, ['key' => 'screen', 'name' => 'Screen', 'value' => '14 inch',
            'source_url' => 'https://example.com/exact-product', 'source_excerpt' => 'Display: 14 inch']];
        $proposal = $editor->proposalFor($draft, $message, ['fields' => ['specifications' => $specifications], 'commercial' => []], 'Добавить подтверждённый экран.');
        $this->assertCount(2, $draft->fresh()->specifications);
        $saved = $editor->apply($draft, $message, $proposal);
        $this->assertSame('https://example.com/exact-product', $saved->specifications[2]['source_url']);
        $this->assertDatabaseCount('ai_runs', 1);
    }

    public function test_proposal_cannot_change_publication_state_and_tampering_is_rejected(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $proposal['patch']['fields']['status'] = 'approved';

        $this->expectException(RuntimeException::class);
        $editor->apply($draft, $message, $proposal);
    }

    public function test_duplicate_specification_keys_are_rejected_before_a_confirmation_exists(): void
    {
        [$draft, $message] = $this->draft();
        $this->expectException(RuntimeException::class);
        app(TelegramDraftEditor::class)->proposalFor($draft, $message, ['fields' => ['specifications' => [
            ['key' => 'memory', 'name' => 'Memory', 'value' => '16 GB'],
            ['key' => 'memory', 'name' => 'Memory upgrade', 'value' => '32 GB'],
        ]]], 'Изменить память');
    }

    public function test_non_list_specifications_are_rejected_before_a_confirmation_exists(): void
    {
        [$draft, $message] = $this->draft();
        $this->expectException(RuntimeException::class);
        app(TelegramDraftEditor::class)->proposalFor($draft, $message, ['fields' => ['specifications' => [
            'memory' => ['key' => 'memory', 'name' => 'Memory', 'value' => '32 GB'],
        ]]], 'Изменить память');
    }

    public function test_explicitly_removing_price_preserves_the_null_in_the_confirmed_metadata(): void
    {
        [$draft, $message] = $this->draft();
        TelegramDraftEditAgent::fake(fn (): array => ['summary' => 'Убрать цену.',
            'changes' => [['field' => 'price_amount', 'name' => null, 'value' => null, 'evidence_quote' => 'Убери цену']]])->preventStrayPrompts();
        $editor = app(TelegramDraftEditor::class);

        $proposal = $editor->propose($draft, $message, 'Убери цену');
        $this->assertStringContainsString('10000.00 → не указано', $proposal['preview']);
        $saved = $editor->apply($draft, $message, $proposal);

        $this->assertArrayHasKey('price_amount', $saved->manualIntake()['commercial']);
        $this->assertNull($saved->manualIntake()['commercial']['price_amount']);
        $this->assertSame('CZK', $saved->manualIntake()['commercial']['currency']);
    }

    public function test_cancelled_generation_cannot_apply_a_prepared_patch(): void
    {
        [$draft, $message] = $this->draft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'описание: Новое описание');
        $draft->telegramUpdate->update(['cancel_requested_at' => now()]);

        $this->expectException(ProductSearchCancelled::class);
        $editor->apply($draft, $message, $proposal);
    }

    private function draft(bool $manual = true): array
    {
        $origin = TelegramUpdate::query()->create([
            'update_id' => 99001, 'chat_id' => 'chat-1', 'telegram_user_id' => 'user-1', 'status' => 'completed',
            'payload' => $manual ? ['_manual_product_upload' => true, '_manual_product_intake' => [
                'status' => 'ready', 'missing_fields' => [], 'conflicts' => [], 'original_description' => 'Original supplied text',
                'commercial' => ['price_amount' => '10000.00', 'currency' => 'CZK'],
            ]] : [],
        ]);
        $initialRun = AiRun::query()->create(['telegram_update_id' => $origin->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Test fixture', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $origin->id, 'ai_run_id' => $initialRun->id, 'requested_by_telegram_user_id' => 'user-1', 'status' => 'pending_review',
            'title' => 'Test laptop', 'model' => 'Test 14', 'description' => 'Original description',
            'condition' => 'new', 'category' => 'laptops', 'product_type' => 'laptop',
            'specifications' => [['key' => 'memory', 'name' => 'Memory', 'value' => '16 GB'], ['key' => 'storage', 'name' => 'Storage', 'value' => '512 GB']],
            'sources' => [], 'image_urls' => [], 'specification_evidence' => [],
        ]);
        $draft->media()->create(['disk' => 'public', 'path' => 'draft/test.jpg', 'checksum' => 'original-checksum',
            'verification_status' => 'manual', 'width' => 1200, 'height' => 800, 'is_primary' => true]);
        $message = TelegramUpdate::query()->create([
            'update_id' => 99002, 'chat_id' => 'chat-1', 'telegram_user_id' => 'user-1', 'status' => 'received', 'payload' => [],
        ]);

        return [$draft, $message];
    }
}
