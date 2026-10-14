<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductTextEvidenceAgent;
use App\Jobs\EnrichTelegramProductDraft;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProcessTelegramProductUpload;
use App\Jobs\ProposeTelegramDraftEdit;
use App\Jobs\StoreProductImages;
use App\Jobs\TranscribeTelegramPhoto;
use App\Jobs\TranscribeTelegramVoice;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Telegram\DraftTelegramInteractionState;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\ProductCardPresenter;
use App\Services\Telegram\TelegramCatalogDraftMedia;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramDraftEditor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramManualCrudFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $updateNumber = 950000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Storage::fake('public');
        Cache::flush();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'test-secret',
            'services.telegram.allowed_user_ids' => ['12345']]);
        $message = 1000;
        Http::fake(fn (Request $request) => Http::response(['ok' => true, 'result' => str_ends_with($request->url(), '/sendMediaGroup')
            ? [['message_id' => ++$message]] : ['message_id' => ++$message]], 200));
    }

    public function test_captioned_album_goes_to_upload_not_search_and_duplicate_update_is_ignored(): void
    {
        $payload = ['update_id' => ++$this->updateNumber, 'message' => ['message_id' => 55, 'from' => ['id' => 12345],
            'chat' => ['id' => 12345], 'caption' => 'My Dell, used, 16 GB', 'media_group_id' => 'album-a',
            'photo' => [['file_id' => 'photo-a', 'width' => 1200, 'height' => 800]]]];
        $this->postJson('/api/telegram/webhook', $payload, $this->headers())->assertOk();
        $this->postJson('/api/telegram/webhook', $payload, $this->headers())->assertJson(['duplicate' => true]);
        Queue::assertPushed(ProcessTelegramProductUpload::class, 1);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_add_text_is_a_manual_draft_and_cancel_clears_capture(): void
    {
        $this->message('/add');
        $this->message('Dell laptop, used, RAM 16 GB');
        Queue::assertPushed(ProcessTelegramProductUpload::class, 1);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->message('/add');
        $this->message('/cancel');
        $this->message('Find Lenovo laptop');
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_opening_an_edit_from_add_mode_does_not_create_another_uploaded_product(): void
    {
        $draft = $this->manualDraft();
        $this->message('/add');
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->message('цена: 15000 CZK');

        Queue::assertPushed(ProposeTelegramDraftEdit::class, 1);
        Queue::assertNotPushed(ProcessTelegramProductUpload::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_explicit_find_exits_edit_input_and_searches_the_entered_query(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->message('/find Dell Latitude 5450');

        Queue::assertNotPushed(ProposeTelegramDraftEdit::class);
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
        $this->assertSame('Dell Latitude 5450', TelegramUpdate::latest('id')->first()->text);
    }

    public function test_a_late_manual_edit_does_not_turn_into_a_paid_search_after_input_expiry(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->travel(11)->minutes();
        $this->message('цена: 12000 CZK');

        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Queue::assertNotPushed(ProposeTelegramDraftEdit::class);
        Queue::assertNotPushed(ProcessTelegramProductUpload::class);
        $this->message('/find New laptop');
        Queue::assertPushed(ProcessTelegramMessage::class, 1);
    }

    public function test_a_late_add_photo_does_not_fall_into_photo_ocr_and_search_after_input_expiry(): void
    {
        $this->message('/add');
        $this->travel(11)->minutes();
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'message' => ['message_id' => 71,
            'from' => ['id' => 12345], 'chat' => ['id' => 12345], 'photo' => [['file_id' => 'late-photo', 'width' => 1200, 'height' => 800]]]], $this->headers())->assertOk();

        Queue::assertNotPushed(TranscribeTelegramPhoto::class);
        Queue::assertNotPushed(ProcessTelegramProductUpload::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_voice_while_awaiting_uploaded_photos_does_not_start_another_search(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:upload:{$draft->id}");
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'message' => ['message_id' => 73,
            'from' => ['id' => 12345], 'chat' => ['id' => 12345], 'voice' => ['file_id' => 'voice', 'duration' => 2]]], $this->headers())->assertOk();

        Queue::assertNotPushed(TranscribeTelegramVoice::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertSame('manual_photos', app(DraftTelegramInteractionState::class)->get('12345', '12345')['kind']);
    }

    public function test_typing_yes_at_a_paid_confirmation_neither_confirms_nor_dispatches_a_search(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:enrich:{$draft->id}");
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->message('да');

        Queue::assertNotPushed(EnrichTelegramProductDraft::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->assertSame($state['token'], app(DraftTelegramInteractionState::class)->get('12345', '12345')['token']);
    }

    public function test_cancel_before_edit_job_starts_prevents_the_parser_and_clears_its_busy_marker(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->message('цена: 12300 CZK');
        $job = Queue::pushed(ProposeTelegramDraftEdit::class)->first();
        $this->message('/cancel');
        $runsBefore = AiRun::count();

        $job->handle(app(TelegramDraftEditor::class), app(TelegramClient::class), app(DraftTelegramPresenter::class));

        $this->assertSame($runsBefore, AiRun::count());
        $this->assertSame('cancelled', TelegramUpdate::find($job->updateId)->status);
        $this->assertFalse(Cache::has("draft-fields:{$draft->id}:queued"));
        $this->assertNull(app(DraftTelegramInteractionState::class)->get('12345', '12345'));
    }

    public function test_a_background_preview_does_not_replace_input_for_another_draft_and_can_be_opened_later(): void
    {
        $first = $this->manualDraft();
        $second = $this->manualDraft();
        $this->pressButton($first, "draft:fields:{$first->id}");
        $this->message('цена: 13500 CZK');
        $job = Queue::pushed(ProposeTelegramDraftEdit::class)->first();
        $this->pressButton($second, "draft:fields:{$second->id}");
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');

        $job->handle(app(TelegramDraftEditor::class), app(TelegramClient::class), app(DraftTelegramPresenter::class));

        $this->assertSame($state['token'], app(DraftTelegramInteractionState::class)->get('12345', '12345')['token']);
        $this->assertSame('manual_fields', app(DraftTelegramInteractionState::class)->get('12345', '12345')['kind']);
        $callback = null;
        foreach (Http::recorded() as [$request]) {
            $value = data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data');
            if (is_string($value) && str_starts_with($value, 'draft:resume-edit:')) {
                $callback = $value;
            }
        }
        $this->assertNotNull($callback);
        $this->pressButton($first, $callback);
        $ready = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->assertSame('confirmation', $ready['kind']);
        $this->assertSame($first->id, $ready['draft_id']);
        $this->pressButton($first, "draft:confirm:{$first->id}:{$ready['token']}");
        $this->assertSame('13500.00', $first->fresh()->manualIntake()['commercial']['price_amount']);
        $this->assertArrayNotHasKey('price_amount', $second->fresh()->manualIntake()['commercial']);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_an_unknown_slash_command_does_not_consume_the_add_prompt_or_buy_ai(): void
    {
        $this->message('/add');
        $this->message('/unknown-command');
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->message('My laptop, used');
        Queue::assertPushed(ProcessTelegramProductUpload::class, 1);
    }

    public function test_a_duplicate_finished_job_cannot_clear_the_marker_for_new_work_on_the_same_draft(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->message('цена: 14000 CZK');
        $job = Queue::pushed(ProposeTelegramDraftEdit::class)->first();
        TelegramUpdate::find($job->updateId)->update(['status' => 'completed', 'processed_at' => now()]);
        Cache::put("draft-fields:{$draft->id}:queued", 999999, 240);

        $job->handle(app(TelegramDraftEditor::class), app(TelegramClient::class), app(DraftTelegramPresenter::class));

        $this->assertSame(999999, Cache::get("draft-fields:{$draft->id}:queued"));
    }

    public function test_edit_is_previewed_then_applied_to_draft_before_separate_catalog_approval(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:fields:{$draft->id}");
        $this->message('цена: 15000 CZK');
        Queue::assertPushed(ProposeTelegramDraftEdit::class, 1);
        $job = Queue::pushed(ProposeTelegramDraftEdit::class)->first();
        $job->handle(app(TelegramDraftEditor::class), app(TelegramClient::class), app(DraftTelegramPresenter::class));
        $this->assertArrayNotHasKey('price_amount', $draft->fresh()->manualIntake()['commercial']);
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->pressButton($draft, "draft:confirm:{$draft->id}:{$state['token']}");
        $this->assertEquals(15000, $draft->fresh()->manualIntake()['commercial']['price_amount']);
        $this->assertDatabaseCount('products', 0);
        $this->pressButton($draft, "draft:add:{$draft->id}");
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('product_variants', ['price' => 15000, 'currency' => 'CZK', 'condition' => 'used']);
        Queue::assertPushed(StoreProductImages::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_extra_information_requires_confirmation_and_does_not_touch_photos(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:enrich:{$draft->id}");
        Queue::assertNotPushed(EnrichTelegramProductDraft::class);
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->pressButton($draft, "draft:confirm:{$draft->id}:{$state['token']}");
        Queue::assertPushed(EnrichTelegramProductDraft::class, 1);
        $this->assertSame(1, $draft->media()->count());
        $this->assertDatabaseCount('products', 0);
    }

    public function test_enrichment_previews_cited_facts_without_publishing_or_repeating_completed_job(): void
    {
        $draft = $this->manualDraft();
        $group = ['id' => null, 'name' => 'Connectivity', 'name_cs' => null];
        ProductTextEvidenceAgent::fake(fn () => ['facts' => [[
            'key' => 'usb_ports', 'name' => 'USB ports', 'value' => '3', 'scope' => 'exact_configuration',
            'group' => $group,
            'source_url' => 'https://maker.example/m123', 'source_excerpt' => 'Three USB ports', 'applicability' => 'Exact M123 configuration',
        ]], 'summary' => 'Confirmed USB ports'])->preventStrayPrompts();
        $this->pressButton($draft, "draft:enrich:{$draft->id}");
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->pressButton($draft, "draft:confirm:{$draft->id}:{$state['token']}");
        $job = Queue::pushed(EnrichTelegramProductDraft::class)->first();
        $job->handle(app(TelegramClient::class), app(DraftTelegramPresenter::class), app(TelegramDraftEditor::class));
        $this->assertSame([], $draft->fresh()->specifications);
        $this->assertCount(1, $draft->fresh()->specification_evidence);
        $this->assertSame($group, $draft->fresh()->specification_evidence[0]['group']);
        $this->assertDatabaseCount('attribute_groups', 0);
        $runs = AiRun::count();
        $job->handle(app(TelegramClient::class), app(DraftTelegramPresenter::class), app(TelegramDraftEditor::class));
        $this->assertSame($runs, AiRun::count());
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->pressButton($draft, "draft:confirm:{$draft->id}:{$state['token']}");
        $this->assertSame('https://maker.example/m123', $draft->fresh()->specifications[0]['source_url']);
        $this->assertSame($group, $draft->fresh()->specifications[0]['group']);
        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertSame(1, $draft->media()->count());
        $this->assertDatabaseCount('products', 0);
    }

    public function test_changes_during_web_search_are_not_applied_to_another_product(): void
    {
        $draft = $this->manualDraft();
        ProductTextEvidenceAgent::fake(function () use ($draft) {
            $draft->update(['model' => 'A different model']);

            return ['facts' => [['key' => 'usb_ports', 'name' => 'USB ports', 'value' => '3', 'scope' => 'exact_configuration',
                'source_url' => 'https://maker.example/m123', 'source_excerpt' => 'Three USB ports', 'applicability' => 'M123 only']],
                'summary' => 'Original product observations'];
        })->preventStrayPrompts();
        $this->pressButton($draft, "draft:enrich:{$draft->id}");
        $state = app(DraftTelegramInteractionState::class)->get('12345', '12345');
        $this->pressButton($draft, "draft:confirm:{$draft->id}:{$state['token']}");
        $job = Queue::pushed(EnrichTelegramProductDraft::class)->first();
        $job->handle(app(TelegramClient::class), app(DraftTelegramPresenter::class), app(TelegramDraftEditor::class));
        $this->assertSame([], $draft->fresh()->specifications);
        $this->assertSame([], $draft->fresh()->specification_evidence);
        $this->assertNull(app(DraftTelegramInteractionState::class)->get('12345', '12345'));
    }

    public function test_manual_intake_does_not_turn_voice_into_an_unconfirmed_web_search(): void
    {
        $this->message('/add');
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'message' => [
            'message_id' => 73, 'from' => ['id' => 12345], 'chat' => ['id' => 12345],
            'voice' => ['file_id' => 'voice-a', 'mime_type' => 'audio/ogg'],
        ]], $this->headers())->assertOk();
        Queue::assertNotPushed(TranscribeTelegramVoice::class);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_upload_button_targets_same_draft_and_blocks_plain_text_from_search(): void
    {
        $draft = $this->manualDraft();
        $this->pressButton($draft, "draft:upload:{$draft->id}");
        $this->message('This text should not accidentally start a search');
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'message' => [
            'message_id' => 72, 'from' => ['id' => 12345], 'chat' => ['id' => 12345],
            'document' => ['file_id' => 'original-photo', 'mime_type' => 'image/jpeg'],
        ]], $this->headers())->assertOk();
        Queue::assertPushed(ProcessTelegramProductUpload::class, fn ($job) => data_get(TelegramUpdate::find($job->telegramUpdateId)->payload, '_manual_product_draft_id') === $draft->id);
    }

    public function test_missing_condition_cannot_be_published_as_new(): void
    {
        $draft = $this->manualDraft();
        $draft->update(['condition' => '']);
        $this->expectException(\RuntimeException::class);
        app(ProductDraftWorkflow::class)->approve($draft);
    }

    public function test_catalog_edit_can_preserve_product_and_variant_identity(): void
    {
        $draft = $this->manualDraft();
        $product = app(ProductDraftWorkflow::class)->approve($draft);
        $image = $this->jpeg();
        Storage::disk('public')->put('existing.jpg', $image);
        $product->media()->create(['disk' => 'public', 'path' => 'existing.jpg', 'type' => 'image', 'width' => 320, 'height' => 240,
            'checksum' => hash('sha256', $image), 'verification_status' => 'manual', 'product_variant_id' => $product->defaultVariant->id]);
        $this->message('/edit '.$product->id);
        $edit = ProductDraft::latest('id')->first();
        $this->assertNotSame($draft->id, $edit->id);
        $this->assertSame($product->id, $edit->manualIntake()['target_product_id']);
        $edit->update(['description' => 'Updated by operator']);
        $saved = app(ProductDraftWorkflow::class)->approve($edit);
        $this->assertSame($product->id, $saved->id);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_catalog_edit_removes_a_spec_and_photo_without_touching_another_variant(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->manualDraft());
        $variant = $product->defaultVariant;
        $definition = AttributeDefinition::create(['key' => 'custom_property', 'label' => 'Custom property', 'data_type' => 'text']);
        $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => 'Original value']);
        $otherVariant = $product->variants()->create(['name' => 'Other configuration', 'condition' => 'new', 'currency' => 'CZK']);
        $oldMedia = [];
        foreach ([[$variant->id, 1], [$variant->id, 2], [$otherVariant->id, 3]] as [$variantId, $color]) {
            $bytes = $this->jpeg($color);
            Storage::disk('public')->put('photo-'.$color.'.jpg', $bytes);
            $oldMedia[] = $product->media()->create(['product_variant_id' => $variantId, 'disk' => 'public', 'path' => 'photo-'.$color.'.jpg',
                'type' => 'image', 'width' => 320, 'height' => 240, 'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual']);
        }
        $this->message('/edit '.$product->id.' '.$variant->id);
        $edit = ProductDraft::latest('id')->first();
        $edit->media()->where('checksum', $oldMedia[1]->checksum)->firstOrFail()->delete();
        $edit->update(['specifications' => []]);
        app(ProductDraftWorkflow::class)->approve($edit);
        $commit = app(TelegramCatalogDraftMedia::class);
        $this->assertSame(1, $commit->commit($product, $variant, $edit));
        $this->assertSame(0, $commit->commit($product, $variant, $edit->fresh()));
        $this->assertDatabaseMissing('attribute_values', ['product_id' => $product->id, 'attribute_definition_id' => $definition->id]);
        $this->assertDatabaseHas('attribute_values', ['product_variant_id' => $otherVariant->id, 'attribute_definition_id' => $definition->id, 'value' => 'Original value']);
        $this->assertDatabaseMissing('product_media', ['id' => $oldMedia[1]->id]);
        $this->assertDatabaseHas('product_media', ['id' => $oldMedia[2]->id, 'product_variant_id' => $otherVariant->id]);
        Storage::disk('public')->assertExists($oldMedia[2]->path);
        $this->assertSame(0, $edit->media()->count());
    }

    public function test_edit_offers_variant_choice_and_only_updates_the_selected_price(): void
    {
        $original = $this->manualDraft();
        $product = app(ProductDraftWorkflow::class)->approve($original);
        $default = $product->defaultVariant;
        $default->update(['price' => 10000]);
        $other = $product->variants()->create(['name' => '32 GB', 'condition' => 'used', 'currency' => 'CZK', 'price' => 20000, 'quantity' => 0, 'stock_status' => 'unknown']);
        $bytes = $this->jpeg(3);
        Storage::disk('public')->put('other-choice.jpg', $bytes);
        $product->media()->create(['product_variant_id' => $other->id, 'disk' => 'public', 'path' => 'other-choice.jpg',
            'type' => 'image', 'width' => 320, 'height' => 240, 'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual']);
        $before = ProductDraft::count();
        $this->message('/edit '.$product->id);
        $this->assertSame($before, ProductDraft::count());
        Http::assertSent(fn (Request $request) => str_contains((string) data_get($request->data(), 'text'), 'Выберите комплектацию')
            && data_get($request->data(), 'reply_markup.inline_keyboard.1.0.callback_data') === "product:tgedit:{$product->id}:{$other->id}");
        $this->pressButton($original, "product:tgedit:{$product->id}:{$other->id}");
        $edit = ProductDraft::latest('id')->first();
        $this->assertSame($other->id, $edit->manualIntake()['target_variant_id']);
        $update = $edit->telegramUpdate;
        $proposal = app(TelegramDraftEditor::class)->propose($edit, $update, 'цена: 18000 CZK');
        app(TelegramDraftEditor::class)->apply($edit, $update, $proposal);
        app(ProductDraftWorkflow::class)->approve($edit->fresh());
        $this->assertEquals(18000, $other->fresh()->price);
        $this->assertSame('32 GB', $other->fresh()->name);
        $this->assertSame('unknown', $other->fresh()->stock_status);
        $this->assertSame($other->fingerprint, $other->fresh()->fingerprint);
        $this->assertEquals(10000, $default->fresh()->price);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_editing_an_inherited_spec_preserves_siblings_and_existing_custom_variant_keys(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->manualDraft());
        $variant = $product->defaultVariant;
        $other = $product->variants()->create(['name' => 'Sibling', 'condition' => 'used', 'currency' => 'CZK']);
        $global = AttributeDefinition::create(['key' => 'keyboard-layout', 'label' => 'Keyboard layout', 'data_type' => 'text']);
        $custom = AttributeDefinition::create(['key' => 'screen-finish', 'label' => 'Screen finish', 'data_type' => 'text']);
        $numeric = AttributeDefinition::create(['key' => 'battery-capacity', 'label' => 'Battery capacity', 'data_type' => 'number', 'default_unit' => 'Wh']);
        $definitionsBefore = AttributeDefinition::count();
        $product->attributes()->create(['attribute_definition_id' => $global->id, 'value' => 'US']);
        $product->attributes()->create(['attribute_definition_id' => $custom->id, 'value' => 'Glossy']);
        $variant->attributes()->create(['attribute_definition_id' => $custom->id, 'value' => 'Matte']);
        $variant->attributes()->create(['attribute_definition_id' => $numeric->id, 'value' => '62', 'numeric_value' => 62, 'unit' => 'Wh']);
        $bytes = $this->jpeg(1);
        Storage::disk('public')->put('attributes-edit.jpg', $bytes);
        $product->media()->create(['product_variant_id' => $variant->id, 'disk' => 'public', 'path' => 'attributes-edit.jpg',
            'type' => 'image', 'width' => 320, 'height' => 240, 'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual']);
        $this->message('/edit '.$product->id.' '.$variant->id);
        $edit = ProductDraft::latest('id')->first();
        $specs = array_map(fn ($spec) => $spec['key'] === 'keyboard-layout' ? [...$spec, 'value' => 'CZ'] : $spec, $edit->specifications);
        $edit->update(['specifications' => $specs]);
        app(ProductDraftWorkflow::class)->approve($edit);
        $this->assertDatabaseHas('attribute_values', ['product_variant_id' => $variant->id, 'attribute_definition_id' => $global->id, 'value' => 'CZ']);
        $this->assertDatabaseHas('attribute_values', ['product_variant_id' => $other->id, 'attribute_definition_id' => $global->id, 'value' => 'US']);
        $this->assertDatabaseHas('attribute_values', ['product_variant_id' => $variant->id, 'attribute_definition_id' => $custom->id, 'value' => 'Matte']);
        $this->assertDatabaseHas('attribute_values', ['product_id' => $product->id, 'attribute_definition_id' => $custom->id, 'value' => 'Glossy']);
        $this->assertDatabaseHas('attribute_values', ['product_variant_id' => $variant->id, 'attribute_definition_id' => $numeric->id, 'value' => '62', 'numeric_value' => 62, 'unit' => 'Wh']);
        $this->assertDatabaseCount('attribute_definitions', $definitionsBefore);
        $caption = app(ProductCardPresenter::class)->caption($product->fresh(), variantId: $variant->id);
        $this->assertStringContainsString('Screen finish: Matte', $caption);
        $this->assertStringNotContainsString('Glossy', $caption);
    }

    public function test_catalog_mutation_after_opening_editor_blocks_stale_confirmation(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->manualDraft());
        $bytes = $this->jpeg();
        Storage::disk('public')->put('existing-race.jpg', $bytes);
        $product->media()->create(['product_variant_id' => $product->defaultVariant->id, 'disk' => 'public', 'path' => 'existing-race.jpg',
            'type' => 'image', 'width' => 320, 'height' => 240, 'checksum' => hash('sha256', $bytes), 'verification_status' => 'manual']);
        $this->message('/edit '.$product->id);
        $edit = ProductDraft::latest('id')->first();
        $product->update(['description' => 'A newer edit in Filament']);
        $this->expectExceptionMessage('Товар изменён');
        app(ProductDraftWorkflow::class)->approve($edit);
    }

    private function manualDraft(): ProductDraft
    {
        $update = TelegramUpdate::create(['update_id' => ++$this->updateNumber, 'chat_id' => '12345', 'telegram_user_id' => '12345',
            'status' => 'completed', 'processed_at' => now(), 'payload' => ['_manual_product_upload' => true,
                '_manual_product_intake' => ['status' => 'ready', 'commercial' => [], 'missing_fields' => [], 'conflicts' => []]]]);
        $run = AiRun::create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'prompt' => 'Local test fixture', 'started_at' => now(), 'status' => 'completed', 'usage' => []]);
        $draft = ProductDraft::create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '12345',
            'title' => 'Operator laptop', 'brand' => 'Test', 'model' => 'M123', 'description' => 'User supplied laptop.', 'category' => 'laptops',
            'product_type' => 'laptop', 'condition' => 'used', 'sources' => [], 'image_urls' => [], 'specifications' => [], 'specification_evidence' => [],
            'images_staged_at' => now(), 'gallery_status' => 'complete']);
        $image = $this->jpeg();
        Storage::disk('public')->put('manual.jpg', $image);
        $draft->media()->create(['disk' => 'public', 'path' => 'manual.jpg', 'width' => 320, 'height' => 240,
            'checksum' => hash('sha256', $image), 'verification_status' => 'manual', 'is_primary' => true]);
        app(DraftTelegramPresenter::class)->sendReview(app(TelegramClient::class), '12345', $draft);

        return $draft->fresh();
    }

    private function message(string $text): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'message' => ['message_id' => $this->updateNumber,
            'from' => ['id' => 12345], 'chat' => ['id' => 12345], 'text' => $text]], $this->headers())->assertOk();
    }

    private function pressButton(ProductDraft $draft, string $data): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => ++$this->updateNumber, 'callback_query' => ['id' => 'click-'.$this->updateNumber,
            'from' => ['id' => 12345], 'data' => $data, 'message' => ['message_id' => $draft->fresh()->telegram_control_message_ids[0] ?? 1000,
                'chat' => ['id' => 12345]]]], $this->headers())->assertOk();
    }

    private function headers(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret'];
    }

    private function jpeg(int $color = 0): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, $color * 60, $color * 40, $color * 20));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
