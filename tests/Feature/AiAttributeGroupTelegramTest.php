<?php

namespace Tests\Feature;

use App\Ai\Agents\TelegramDraftEditAgent;
use App\Ai\Agents\TelegramProductUploadAgent;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramDraftEditor;
use App\Services\Telegram\TelegramProductUploadIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AiAttributeGroupTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        config(['services.telegram.bot_token' => 'test-token']);
        TelegramProductUploadAgent::fake()->preventStrayPrompts();
        TelegramDraftEditAgent::fake()->preventStrayPrompts();
        Category::query()->firstOrCreate(['slug' => 'laptops'], ['name' => 'Laptops', 'is_active' => true]);
    }

    public function test_upload_keeps_new_and_existing_group_proposals_without_creating_catalog_records(): void
    {
        $graphics = AttributeGroup::query()->create(['name' => 'Graphics']);
        $definitionCount = AttributeDefinition::query()->count();
        $answer = $this->uploadAnswer();
        $answer['specifications'][] = [
            'key' => 'gpu_memory', 'name' => 'GPU memory', 'value' => '8 GB',
            'name_cs' => null, 'value_cs' => null, 'source' => 'description',
            'quote' => 'GPU memory 8 GB', 'photo_index' => null,
            'group' => ['id' => $graphics->id, 'name' => 'Graphics', 'name_cs' => null],
        ];
        TelegramProductUploadAgent::fake([$answer])->preventStrayPrompts();
        $draft = $this->upload();

        $this->assertSame(['id' => null, 'name' => 'Memory', 'name_cs' => 'Paměť'], $draft->specifications[0]['group']);
        $this->assertSame($graphics->id, $draft->specifications[1]['group']['id']);
        $this->assertSame('16 GB RAM', $draft->specifications[0]['source_excerpt']);
        $this->assertNull($draft->specifications[0]['source_url']);
        $this->assertDatabaseCount('attribute_groups', 1);
        $this->assertDatabaseCount('attribute_definitions', $definitionCount);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_appending_photos_keeps_the_existing_proposal_and_ignores_new_ai_grouping(): void
    {
        $replacement = $this->uploadAnswer(2);
        $replacement['specifications'][0]['group']['name'] = 'Wrong replacement';
        TelegramProductUploadAgent::fake([$this->uploadAnswer(), $replacement])->preventStrayPrompts();
        $draft = $this->upload();
        $before = $draft->specifications;
        $intake = app(TelegramProductUploadIntake::class);
        $next = $this->photo(91002, 'second');
        $intake->queue($next, draftId: $draft->id);

        $saved = $intake->createFromUpdates(collect([$next->fresh()]));

        $this->assertSame($before, $saved->specifications);
        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertSame(2, $saved->media()->count());
    }

    public function test_edit_preserves_an_omitted_group_and_accepts_a_group_for_a_new_specification(): void
    {
        [$draft, $message] = $this->editableDraft();
        TelegramDraftEditAgent::fake([[
            'summary' => 'Update memory and add GPU memory.',
            'changes' => [
                ['field' => 'specification', 'name' => 'Memory', 'value' => '32 GB', 'evidence_quote' => 'RAM 32 GB'],
                ['field' => 'specification', 'name' => 'GPU memory', 'value' => '8 GB', 'evidence_quote' => 'GPU memory 8 GB',
                    'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => 'Grafika']],
            ],
        ]])->preventStrayPrompts();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->propose($draft, $message, 'RAM 32 GB, GPU memory 8 GB');
        $this->assertCount(1, $draft->fresh()->specifications);

        $saved = $editor->apply($draft, $message, $proposal);

        $this->assertSame('Memory', $saved->specifications[0]['group']['name']);
        $this->assertSame('32 GB', $saved->specifications[0]['value']);
        $this->assertSame('Graphics', $saved->specifications[1]['group']['name']);
        $this->assertSame('GPU memory 8 GB', $saved->specifications[1]['source_excerpt']);
        $this->assertDatabaseCount('attribute_groups', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_full_patch_preserves_group_by_key_without_copying_it_to_a_replacement_key(): void
    {
        [$draft, $message] = $this->editableDraft();
        $editor = app(TelegramDraftEditor::class);
        $proposal = $editor->proposalFor($draft, $message, ['fields' => ['specifications' => [
            ['key' => 'ram', 'name' => 'Memory', 'value' => '32 GB'],
            ['key' => 'gpu_memory', 'name' => 'GPU memory', 'value' => '8 GB'],
        ]]], 'Verified enrichment');

        $saved = $editor->apply($draft, $message, $proposal);

        $this->assertSame('Memory', $saved->specifications[0]['group']['name']);
        $this->assertArrayNotHasKey('group', $saved->specifications[1]);
        $this->assertDatabaseCount('attribute_groups', 0);
    }

    public function test_a_group_proposal_does_not_allow_an_uncited_specification_change(): void
    {
        [$draft, $message] = $this->editableDraft();
        TelegramDraftEditAgent::fake([[
            'summary' => 'Add GPU memory.',
            'changes' => [['field' => 'specification', 'name' => 'GPU memory', 'value' => '8 GB',
                'evidence_quote' => 'GPU memory 8 GB',
                'group' => ['id' => null, 'name' => 'Graphics', 'name_cs' => null]]],
        ]])->preventStrayPrompts();

        try {
            app(TelegramDraftEditor::class)->propose($draft, $message, 'Change the description only');
            $this->fail('Grouping cannot provide product evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('без цитаты', $exception->getMessage());
        }
        $this->assertCount(1, $draft->fresh()->specifications);
        $this->assertDatabaseCount('attribute_groups', 0);
    }

    private function editableDraft(): array
    {
        $origin = TelegramUpdate::query()->create(['update_id' => 91010, 'chat_id' => '123',
            'telegram_user_id' => '456', 'status' => 'completed', 'payload' => ['_manual_product_upload' => true,
                '_manual_product_intake' => ['status' => 'ready', 'missing_fields' => [], 'conflicts' => []]]]);
        $run = AiRun::query()->create(['telegram_update_id' => $origin->id, 'provider' => 'local',
            'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now()]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $origin->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '456',
            'status' => 'pending_review', 'title' => 'Test laptop', 'category' => 'laptops',
            'sources' => [], 'image_urls' => [], 'specification_evidence' => [],
            'condition' => 'new', 'product_type' => 'laptop', 'specifications' => [
                ['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB',
                    'group' => ['id' => null, 'name' => 'Memory', 'name_cs' => 'Paměť']],
            ],
        ]);
        $message = TelegramUpdate::query()->create(['update_id' => 91011, 'chat_id' => '123',
            'telegram_user_id' => '456', 'status' => 'received', 'payload' => []]);

        return [$draft, $message];
    }

    private function upload(): ProductDraft
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/getFile')) {
                return Http::response(['ok' => true, 'result' => ['file_path' => 'photo/'.$request['file_id'].'.jpg']]);
            }
            if (str_contains($request->url(), '/file/bot')) {
                $image = imagecreatetruecolor(320, 240);
                imagefill($image, 0, 0, imagecolorallocate($image, str_contains($request->url(), 'second') ? 70 : 30, 40, 50));
                ob_start();
                imagejpeg($image);
                $bytes = ob_get_clean();
                imagedestroy($image);

                return Http::response($bytes);
            }

            throw new RuntimeException('Unexpected HTTP request: '.$request->url());
        });

        return app(TelegramProductUploadIntake::class)->createFromUpdates(collect([$this->photo(91001, 'first')]));
    }

    private function photo(int $id, string $file): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => $id, 'chat_id' => '123',
            'telegram_user_id' => '456', 'message_id' => $id, 'status' => 'received', 'payload' => [
                'message' => ['photo' => [['file_id' => $file, 'width' => 320, 'height' => 240]],
                    'caption' => 'Laptop, new, 16 GB RAM, GPU memory 8 GB'],
            ]]);
    }

    private function uploadAnswer(int $photos = 1): array
    {
        return ['title' => 'Test laptop', 'brand' => null, 'model' => null, 'product_type' => 'laptop',
            'category' => 'laptops', 'color' => null, 'condition' => 'new', 'description' => 'Laptop with 16 GB RAM',
            'title_cs' => null, 'description_cs' => null, 'specifications' => [
                ['key' => 'ram', 'name' => 'Memory', 'value' => '16 GB', 'name_cs' => 'Paměť',
                    'value_cs' => '16 GB', 'source' => 'description', 'quote' => '16 GB RAM', 'photo_index' => null,
                    'group' => ['id' => null, 'name' => ' Memory ', 'name_cs' => ' Paměť ']],
            ], 'photos' => array_map(fn (int $index): array => [
                'index' => $index, 'kind' => 'product', 'reason' => 'The supplied laptop is visible.',
            ], range(1, $photos)), 'missing_fields' => [], 'conflicts' => [],
            'commercial' => ['price_amount' => null, 'currency' => null, 'quantity' => null, 'warranty_months' => null],
            'commercial_quotes' => []];
    }
}
