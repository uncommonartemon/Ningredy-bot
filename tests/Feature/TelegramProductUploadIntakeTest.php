<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramProductUploadAgent;
use App\Jobs\ProcessTelegramMessage;
use App\Jobs\ProcessTelegramProductUpload;
use App\Jobs\TranscribeTelegramPhoto;
use App\Models\AiRun;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramCatalogEditor;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProductUploadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramProductUploadIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true,
            'product_type_affinity' => 'laptop']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/'.$request['file_id'].'.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($this->jpeg(str_contains($request->url(), 'second') ? 70
                    : (str_contains($request->url(), 'third') ? 110 : 30)));
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
    }

    #[DataProvider('uploadCaptions')]
    public function test_captioned_photo_delegates_to_manual_intake_without_starting_search_or_ocr(string $caption): void
    {
        $update = $this->photo(5001, caption: $caption);
        (new TranscribeTelegramPhoto($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        Queue::assertPushed(ProcessTelegramProductUpload::class, fn ($job) => $job->telegramUpdateId === $update->id);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Http::assertNothingSent();
        $this->assertTrue(data_get($update->fresh()->payload, '_manual_product_upload'));
    }

    public static function uploadCaptions(): array
    {
        return [
            'description' => ['Dell Latitude 5450, used, 16 GB RAM'],
            'new product description' => ['Новый MSI Stealth 16, 32 ГБ RAM, 1 ТБ SSD'],
            'negative Russian request' => ['Не ищи в интернете, добавь по описанию'],
            'negative English request' => ["Don't search the web; use my photos and description."],
            'explicit manual command' => ['/add Dell Latitude 5450, find extra information later'],
            'Find is a phone model' => ['OPPO Find X7 Ultra, новый, 512 ГБ'],
        ];
    }

    public function test_explicit_manual_marker_keeps_a_search_caption_in_upload_flow(): void
    {
        $update = $this->photo(5050, caption: 'Search extra information later.');
        $update->update(['payload' => [...$update->payload, '_manual_product_upload' => true]]);
        (new TranscribeTelegramPhoto($update->id))->handle(app(TelegramClient::class), app(AiErrorPresenter::class));

        Queue::assertPushed(ProcessTelegramProductUpload::class, 1);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Http::assertNothingSent();
    }

    public function test_album_and_description_create_one_manual_draft_preserving_origin_and_originals(): void
    {
        TelegramProductUploadAgent::fake(fn (): array => $this->answer(2))->preventStrayPrompts();
        $first = $this->photo(5002, group: 'same-album');
        $second = $this->photo(5003, group: 'same-album', caption: 'Dell Latitude 5450, used, 16 GB RAM', file: 'second');
        $foreign = $this->photo(5004, group: 'same-album', user: 'different');
        $intake = app(TelegramProductUploadIntake::class);

        $draft = $intake->createFromUpdates($intake->groupedUpdates($first));
        $again = $intake->createFromUpdates($intake->groupedUpdates($second));

        $this->assertSame($draft->id, $again->id);
        $this->assertSame(1, ProductDraft::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(2, $draft->media()->count());
        $this->assertSame('used', $draft->condition);
        $this->assertNull($draft->primary_source_url);
        $this->assertSame([], $draft->sources);
        $this->assertSame([], $draft->specification_evidence);
        $this->assertSame('manual', $draft->media()->first()->verification_status);
        $this->assertNull($draft->media()->first()->source_url);
        $meta = data_get($draft->telegramUpdate->payload, '_manual_product_intake');
        $this->assertSame('ready', $meta['status']);
        $this->assertSame('Dell Latitude 5450, used, 16 GB RAM', $meta['original_description']);
        $this->assertCount(2, $meta['uploaded_photos']);
        foreach ($meta['uploaded_photos'] as $file) {
            Storage::disk($file['disk'])->assertExists($file['path']);
        }
        $this->assertNull($foreign->fresh()->processed_at);
        $this->assertSame(1, AiRun::query()->count());
        $this->assertFalse(AiRun::sole()->activity['search_budget']);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
        Http::assertSentCount(4);
    }

    public function test_label_only_and_unknown_condition_stay_incomplete_without_fabricating_a_gallery(): void
    {
        TelegramProductUploadAgent::fake(function (): array {
            $answer = $this->answer(1);
            $answer['condition'] = null;
            $answer['photos'][0]['kind'] = 'label';

            return $answer;
        })->preventStrayPrompts();
        $draft = app(TelegramProductUploadIntake::class)->createFromUpdates(collect([
            $this->photo(5005, caption: 'Dell Latitude 5450, 16 GB RAM'),
        ]));

        $meta = data_get($draft->telegramUpdate->payload, '_manual_product_intake');
        $this->assertSame('needs_input', $meta['status']);
        $this->assertContains('condition', $meta['missing_fields']);
        $this->assertContains('product_photo', $meta['missing_fields']);
        $this->assertSame('', $draft->condition);
        $this->assertSame(0, $draft->media()->count());
        $this->assertCount(1, $meta['uploaded_photos']);
        $this->assertSame(0, Product::query()->count());
    }

    public function test_text_only_manual_draft_can_receive_photos_later_without_publishing_or_searching(): void
    {
        TelegramProductUploadAgent::fake([$this->answer(0), $this->answer(1)])->preventStrayPrompts();
        $text = TelegramUpdate::query()->create(['update_id' => 5010, 'chat_id' => '98765',
            'telegram_user_id' => '12345', 'text' => 'Dell Latitude 5450, used, 16 GB RAM',
            'payload' => ['_manual_product_upload' => true], 'status' => 'received']);
        $intake = app(TelegramProductUploadIntake::class);
        $draft = $intake->createFromUpdates(collect([$text]));
        $upload = $this->photo(5011);
        $intake->queue($upload, draftId: $draft->id);
        $updated = $intake->createFromUpdates(collect([$upload->fresh()]));

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame('ready', data_get($updated->telegramUpdate->payload, '_manual_product_intake.status'));
        $this->assertSame(1, $updated->media()->count());
        $this->assertSame(1, ProductDraft::query()->count());
        $this->assertSame(0, Product::query()->count());
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_agent_cannot_invent_a_specification_quote_missing_from_supplied_description(): void
    {
        TelegramProductUploadAgent::fake(function (): array {
            $answer = $this->answer(1);
            $answer['specifications'][0]['quote'] = '64 GB RAM';

            return $answer;
        })->preventStrayPrompts();
        $update = $this->photo(5012, caption: 'Dell Latitude 5450, used, 16 GB RAM');

        try {
            app(TelegramProductUploadIntake::class)->createFromUpdates(collect([$update]));
            $this->fail('Unobserved specification was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('без цитаты', $exception->getMessage());
        }
        $this->assertSame(0, ProductDraft::query()->count());
        $this->assertSame('failed', AiRun::sole()->status);
        $this->assertNotNull(AiRun::sole()->usage);
        $this->assertCount(1, data_get($update->fresh()->payload, '_manual_product_files'));
    }

    public function test_image_documents_are_accepted_as_manual_material(): void
    {
        TelegramProductUploadAgent::fake(fn (): array => $this->answer(1))->preventStrayPrompts();
        $update = TelegramUpdate::query()->create(['update_id' => 5013, 'chat_id' => '98765',
            'telegram_user_id' => '12345', 'status' => 'received', 'payload' => ['message' => [
                'document' => ['file_id' => 'first', 'mime_type' => 'image/jpeg', 'file_name' => 'product.jpg'],
                'caption' => 'Dell Latitude 5450, used, 16 GB RAM',
            ]]]);
        $draft = app(TelegramProductUploadIntake::class)->createFromUpdates(collect([$update]));

        $this->assertSame(1, $draft->media()->count());
        $this->assertSame('manual', $draft->media()->first()->verification_status);
    }

    public function test_late_album_member_is_added_to_the_same_pending_card_preserving_operator_edits(): void
    {
        TelegramProductUploadAgent::fake([$this->answer(1), $this->answer(2)])->preventStrayPrompts();
        $first = $this->photo(5014, group: 'late-album', caption: 'Dell Latitude 5450, used, 16 GB RAM');
        $draft = app(TelegramProductUploadIntake::class)->createFromUpdates(collect([$first]));
        $draft->update(['description' => 'Operator edited this description.']);
        $late = $this->photo(5015, group: 'late-album', file: 'second');
        $this->travel(4)->seconds();

        (new ProcessTelegramProductUpload($late->id))->handle(app(TelegramProductUploadIntake::class),
            app(TelegramClient::class), app(DraftTelegramPresenter::class), app(AiErrorPresenter::class));

        $this->assertSame(1, ProductDraft::query()->count());
        $this->assertSame(2, $draft->fresh()->media()->count());
        $this->assertSame('Operator edited this description.', $draft->fresh()->description);
        $this->assertNotNull($late->fresh()->processed_at);
        Queue::assertNotPushed(ProcessTelegramMessage::class);
    }

    public function test_conflicting_photo_descriptions_are_shown_without_becoming_ready(): void
    {
        TelegramProductUploadAgent::fake(function (): array {
            $answer = $this->answer(1);
            $answer['conflicts'] = ['The photo shows another model than the supplied description.'];
            $answer['photos'][0]['kind'] = 'uncertain';

            return $answer;
        })->preventStrayPrompts();
        $draft = app(TelegramProductUploadIntake::class)->createFromUpdates(collect([
            $this->photo(5016, caption: 'Dell Latitude 5450, used, 16 GB RAM'),
        ]));

        $this->assertSame('needs_input', data_get($draft->telegramUpdate->payload, '_manual_product_intake.status'));
        $this->assertCount(1, data_get($draft->telegramUpdate->payload, '_manual_product_intake.conflicts'));
        $this->assertSame(0, $draft->media()->count());
    }

    public function test_commercial_terms_are_saved_only_with_observed_operator_quotes(): void
    {
        TelegramProductUploadAgent::fake(function (): array {
            $answer = $this->answer(1);
            $answer['commercial'] = ['price_amount' => '350.00', 'currency' => 'EUR', 'quantity' => 2, 'warranty_months' => 6];
            $answer['commercial_quotes'] = [
                ['field' => 'price_amount', 'quote' => '350.00 EUR'], ['field' => 'currency', 'quote' => '350.00 EUR'],
                ['field' => 'quantity', 'quote' => 'quantity 2'], ['field' => 'warranty_months', 'quote' => 'warranty 6 months'],
            ];

            return $answer;
        })->preventStrayPrompts();
        $draft = app(TelegramProductUploadIntake::class)->createFromUpdates(collect([
            $this->photo(5017, caption: 'Dell Latitude 5450, used, 16 GB RAM, 350.00 EUR, quantity 2, warranty 6 months'),
        ]));

        $commercial = data_get($draft->telegramUpdate->payload, '_manual_product_intake.commercial');
        $this->assertSame('350.00', $commercial['price_amount']);
        $this->assertSame('EUR', $commercial['currency']);
        $this->assertSame(2, $commercial['quantity']);
        $this->assertSame(6, $commercial['warranty_months']);
    }

    public function test_unknown_commercial_terms_are_not_saved_as_agent_guesses(): void
    {
        TelegramProductUploadAgent::fake(function (): array {
            $answer = $this->answer(1);
            $answer['commercial']['price_amount'] = '500.00';

            return $answer;
        })->preventStrayPrompts();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('без цитаты оператора');
        app(TelegramProductUploadIntake::class)->createFromUpdates(collect([
            $this->photo(5018, caption: 'Dell Latitude 5450, used, 16 GB RAM'),
        ]));
    }

    public function test_append_rejects_an_overwrite_if_commercial_terms_changed_during_agent_call(): void
    {
        $draft = null;
        $calls = 0;
        TelegramProductUploadAgent::fake(function () use (&$draft, &$calls): array {
            $calls++;
            if ($calls === 2) {
                $origin = $draft->telegramUpdate->fresh();
                $payload = $origin->payload;
                $payload['_manual_product_intake']['commercial']['price_amount'] = '777.00';
                $origin->update(['payload' => $payload]);
            }

            return $this->answer($calls === 1 ? 0 : 1);
        })->preventStrayPrompts();
        $text = TelegramUpdate::query()->create(['update_id' => 5019, 'chat_id' => '98765',
            'telegram_user_id' => '12345', 'text' => 'Dell Latitude 5450, used, 16 GB RAM',
            'payload' => ['_manual_product_upload' => true], 'status' => 'received']);
        $intake = app(TelegramProductUploadIntake::class);
        $draft = $intake->createFromUpdates(collect([$text]));
        $upload = $this->photo(5020);
        $intake->queue($upload, draftId: $draft->id);

        try {
            $intake->createFromUpdates(collect([$upload->fresh()]));
            $this->fail('Concurrent commercial edit was overwritten.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('изменился', $exception->getMessage());
        }
        $this->assertSame('777.00', data_get($draft->telegramUpdate->fresh()->payload,
            '_manual_product_intake.commercial.price_amount'));
        $this->assertSame(0, $draft->fresh()->media()->count());
    }

    public function test_append_upload_marker_blocks_approval_until_all_queued_material_is_processed(): void
    {
        TelegramProductUploadAgent::fake([$this->answer(1), $this->answer(2)])->preventStrayPrompts();
        $intake = app(TelegramProductUploadIntake::class);
        $draft = $intake->createFromUpdates(collect([
            $this->photo(5021, caption: 'Dell Latitude 5450, used, 16 GB RAM'),
        ]));
        $first = $this->photo(5022, file: 'second');
        $waiting = $this->photo(5023, file: 'second');
        $intake->queue($first, draftId: $draft->id);
        $intake->queue($waiting, draftId: $draft->id);
        $this->assertTrue(Cache::has("draft-upload:{$draft->id}:queued"));

        (new ProcessTelegramProductUpload($first->id))->handle($intake,
            app(TelegramClient::class), app(DraftTelegramPresenter::class), app(AiErrorPresenter::class));

        $this->assertTrue(Cache::has("draft-upload:{$draft->id}:queued"));
        $waiting->update(['processed_at' => now(), 'status' => 'cancelled']);
        $intake->clearUploadMarkerIfIdle($draft->id);
        $this->assertFalse(Cache::has("draft-upload:{$draft->id}:queued"));
    }

    public function test_adding_photos_preserves_existing_web_citations_and_operator_translations(): void
    {
        TelegramProductUploadAgent::fake([$this->answer(1), $this->answer(2)])->preventStrayPrompts();
        $intake = app(TelegramProductUploadIntake::class);
        $draft = $intake->createFromUpdates(collect([
            $this->photo(5024, caption: 'Dell Latitude 5450, used, 16 GB RAM'),
        ]));
        $specs = [...$draft->specifications, ['key' => 'cpu', 'name' => 'Processor', 'value' => 'Intel Core Ultra 5',
            'source_url' => 'https://example.com/exact-card', 'source_excerpt' => 'Intel Core Ultra 5']];
        $observations = [['key' => 'cpu', 'name' => 'Processor', 'value' => 'Intel Core Ultra 5',
            'source_url' => 'https://example.com/exact-card', 'source_excerpt' => 'Intel Core Ultra 5',
            'source_type' => 'manufacturer', 'scope' => 'exact_configuration', 'applicability' => 'Exact model']];
        $sources = [['url' => 'https://example.com/exact-card', 'title' => 'Exact specification', 'type' => 'manufacturer']];
        $translations = $draft->translations;
        $translations['cs']['specifications']['cpu'] = ['name' => 'Procesor', 'value' => 'Intel Core Ultra 5'];
        $draft->update(['specifications' => $specs, 'specification_evidence' => $observations,
            'sources' => $sources, 'translations' => $translations]);
        $upload = $this->photo(5025, file: 'second');
        $intake->queue($upload, draftId: $draft->id);
        $updated = $intake->createFromUpdates(collect([$upload->fresh()]));

        $this->assertSame($specs, $updated->specifications);
        $this->assertSame($observations, $updated->specification_evidence);
        $this->assertSame($sources, $updated->sources);
        $this->assertSame($translations, $updated->translations);
        $this->assertSame(2, $updated->media()->count());
    }

    public function test_appending_to_catalog_snapshot_keeps_existing_photos_and_their_provenance(): void
    {
        $product = Product::query()->create(['title' => 'Dell Latitude 5450', 'model' => 'Latitude 5450',
            'product_type' => 'laptop', 'description' => 'Dell Latitude 5450, used, 16 GB RAM',
            'category_id' => Category::where('slug', 'laptops')->value('id'), 'status' => 'published']);
        $variant = $product->variants()->create(['condition' => 'used', 'currency' => 'EUR', 'is_default' => true]);
        $bytes = $this->jpeg(10);
        $path = 'products/'.$product->id.'/catalog-original.jpg';
        Storage::disk('public')->put($path, $bytes);
        $original = $product->media()->create(['product_variant_id' => $variant->id, 'type' => 'image',
            'disk' => 'public', 'path' => $path, 'checksum' => hash('sha256', $bytes), 'width' => 320, 'height' => 240,
            'verification_status' => 'source_verified', 'source_url' => 'https://example.com/exact-card',
            'verification_notes' => 'The original observed gallery.', 'is_primary' => true]);
        $open = TelegramUpdate::query()->create(['update_id' => 5026, 'chat_id' => '98765', 'telegram_user_id' => '12345',
            'status' => 'received', 'payload' => []]);
        $draft = app(TelegramCatalogEditor::class)->createDraft($product, $open);
        $retained = $draft->media()->first();
        $upload = $this->photo(5027, file: 'second');
        $intake = app(TelegramProductUploadIntake::class);
        TelegramProductUploadAgent::fake(fn (): array => $this->answer(2))->preventStrayPrompts();
        $intake->queue($upload, draftId: $draft->id);
        $updated = $intake->createFromUpdates(collect([$upload->fresh()]));

        $this->assertSame(2, $updated->media()->count());
        $this->assertDatabaseHas('product_draft_media', ['id' => $retained->id, 'path' => $retained->path,
            'verification_status' => 'source_verified', 'source_url' => 'https://example.com/exact-card']);
        $this->assertSame($retained->verification_notes, $retained->fresh()->verification_notes);
        Storage::disk('public')->assertExists($retained->path);
        Storage::disk('public')->assertExists($original->path);
        $this->assertSame($product->id, $updated->manualIntake()['target_product_id']);
    }

    public function test_appending_does_not_restore_photos_previously_deleted_from_the_draft(): void
    {
        TelegramProductUploadAgent::fake([$this->answer(2), $this->answer(2)])->preventStrayPrompts();
        $intake = app(TelegramProductUploadIntake::class);
        $draft = $intake->createFromUpdates(collect([
            $this->photo(5028, group: 'initial-two', caption: 'Dell Latitude 5450, used, 16 GB RAM'),
            $this->photo(5029, group: 'initial-two', file: 'second'),
        ]));
        $deleted = $draft->media()->orderBy('sort_order')->first();
        $deletedChecksum = $deleted->checksum;
        $deleted->delete();
        $upload = $this->photo(5030, file: 'third');
        $intake->queue($upload, draftId: $draft->id);
        $updated = $intake->createFromUpdates(collect([$upload->fresh()]));

        $this->assertSame(2, $updated->media()->count());
        $this->assertFalse($updated->media()->where('checksum', $deletedChecksum)->exists());
        $this->assertCount(3, $updated->manualIntake()['uploaded_photos']);
    }

    private function photo(int $id, ?string $group = null, ?string $caption = null,
        string $file = 'first', string $user = '12345'): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => $id, 'telegram_user_id' => $user,
            'chat_id' => '98765', 'message_id' => $id, 'status' => 'received',
            'payload' => ['message' => ['photo' => [['file_id' => $file, 'width' => 320, 'height' => 240]],
                'media_group_id' => $group, 'caption' => $caption]]]);
    }

    private function answer(int $count): array
    {
        return ['title' => 'Dell Latitude 5450', 'brand' => 'Dell', 'model' => 'Latitude 5450',
            'product_type' => 'laptop', 'category' => 'laptops', 'color' => null, 'condition' => 'used',
            'description' => 'Dell Latitude 5450 laptop with 16 GB RAM, offered in used condition.',
            'title_cs' => 'Dell Latitude 5450', 'description_cs' => 'Použitý notebook Dell Latitude 5450 s 16 GB RAM.',
            'specifications' => [['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB',
                'name_cs' => 'Paměť', 'value_cs' => '16 GB', 'source' => 'description',
                'quote' => '16 GB RAM', 'photo_index' => null]],
            'photos' => array_map(fn (int $index): array => ['index' => $index, 'kind' => 'product',
                'reason' => 'The supplied laptop is visible.'], $count > 0 ? range(1, $count) : []),
            'missing_fields' => [], 'conflicts' => [],
            'commercial' => ['price_amount' => null, 'currency' => null, 'quantity' => null, 'warranty_months' => null],
            'commercial_quotes' => []];
    }

    private function jpeg(int $red = 30): string
    {
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 40, 50));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
