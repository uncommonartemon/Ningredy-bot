<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnreferencedMedia;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductSourcePageEvidence;
use App\Models\TelegramUpdate;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DatabaseOperatorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
    }

    private function service(): DatabaseOperatorService
    {
        return app(DatabaseOperatorService::class);
    }

    private function update(string $user = '5', string $chat = '10'): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => random_int(1, PHP_INT_MAX), 'telegram_user_id' => $user,
            'chat_id' => $chat, 'status' => 'processed', 'processed_at' => now(), 'payload' => []]);
    }

    private function draft(TelegramUpdate $update, string $title = 'Laptop'): ProductDraft
    {
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'status' => 'completed', 'prompt' => 'Fixture', 'started_at' => now()]);

        return ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => $update->telegram_user_id, 'title' => $title,
            'specifications' => [], 'sources' => [], 'image_urls' => []]);
    }

    private function product(): Product
    {
        $category = Category::query()->firstOrFail();

        return Product::query()->create(['category_id' => $category->id, 'title' => 'Laptop', 'status' => 'published', 'is_active' => true]);
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a typed validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_schema_exposes_business_tables_and_explains_protected_tables(): void
    {
        $tables = $this->service()->describe()['tables'];
        $this->assertContains('update', $tables['attribute_definitions']['actions']);
        $this->assertSame(['query'], $tables['ai_runs']['actions']);
        $this->assertFalse($tables['users']['readable']);
        $this->assertNotContains('payload', $tables['telegram_updates']['columns']);
        $this->assertNotContains('specifications_reconciled_source_url', $tables['product_drafts']['writable_columns']);
        $this->assertNotContains('verification_status', $tables['product_media']['writable_columns']);
        $this->assertSame('attribute_groups', $tables['attribute_definitions']['column_details']['attribute_group_id']['foreign_key']['table']);
        $this->assertTrue($tables['attribute_definitions']['column_details']['attribute_group_id']['nullable']);
    }

    public function test_create_group_and_assign_inclusive_attribute_range_atomically(): void
    {
        $definitions = collect(range(1, 8))->map(fn ($n) => AttributeDefinition::query()->create(['label' => 'Memory '.$n, 'is_variant' => false]));
        $from = $definitions[2]->id;
        $to = $definitions[5]->id;
        $plan = $this->service()->prepare([
            ['action' => 'create', 'table' => 'attribute_groups', 'ref' => 'ram', 'values' => ['name' => 'RAM']],
            ['action' => 'update', 'table' => 'attribute_definitions', 'selection' => ['id_from' => $from, 'id_to' => $to], 'values' => ['attribute_group_id' => ['$ref' => 'ram.id']]],
        ]);
        $this->assertSame(0, AttributeGroup::query()->count());
        $this->assertSame(5, $plan['affected_count']);
        $result = $this->service()->execute($plan);
        $group = AttributeGroup::query()->sole();
        $this->assertSame($group->id, $result['references']['ram']['id']);
        $this->assertSame(4, $group->attributeDefinitions()->count());
        $this->assertNull($definitions[1]->fresh()->attribute_group_id);
        $this->assertNull($definitions[6]->fresh()->attribute_group_id);
    }

    public function test_group_delete_preview_lists_the_exact_attribute_detachments(): void
    {
        $group = AttributeGroup::query()->create(['name' => 'RAM']);
        AttributeDefinition::query()->create(['label' => 'Capacity', 'attribute_group_id' => $group->id]);
        AttributeDefinition::query()->create(['label' => 'Frequency', 'attribute_group_id' => $group->id]);
        AttributeDefinition::query()->create(['label' => 'Outside group']);
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'attribute_groups', 'selection' => ['ids' => [$group->id]]]]);

        $this->assertSame([['table' => 'attribute_definitions', 'count' => 2, 'action' => 'update', 'values' => ['attribute_group_id' => null]]], $plan['commands'][0]['effects']);
        $preview = app(TelegramDatabaseOperator::class)->preview(new AiOperation(['payload' => ['plan' => $plan]]));
        $this->assertStringContainsString('attribute_definitions: 2', $preview);
        $this->assertStringContainsString('"attribute_group_id":null', $preview);
        $this->assertSame(2, $group->attributeDefinitions()->count());
    }

    public function test_product_delete_preview_lists_variant_attributes_media_and_approval_detachments(): void
    {
        $product = $this->product();
        $variant = $product->variants()->create(['sku' => 'CASCADE', 'is_default' => true]);
        $definition = AttributeDefinition::query()->create(['label' => 'RAM', 'is_variant' => true]);
        $variant->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => '16 GB']);
        $product->media()->create(['product_variant_id' => $variant->id, 'disk' => 'public', 'path' => 'products/'.$product->id.'/front.jpg']);
        $product->sources()->create(['product_variant_id' => $variant->id, 'title' => 'Store', 'url' => 'https://example.com/item', 'domain' => 'example.com']);
        $draft = $this->draft($this->update());
        $draft->update(['status' => 'approved', 'approved_product_id' => $product->id, 'approved_variant_id' => $variant->id]);
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'products', 'selection' => ['ids' => [$product->id]]]]);

        $effects = $plan['commands'][0]['effects'];
        foreach (['product_variants', 'product_media', 'product_sources', 'attribute_values'] as $table) {
            $this->assertContains(['table' => $table, 'count' => 1, 'action' => 'delete', 'values' => []], $effects);
        }
        foreach (['approved_product_id', 'approved_variant_id'] as $field) {
            $this->assertContains(['table' => 'product_drafts', 'count' => 1, 'action' => 'update', 'values' => [$field => null]], $effects);
        }
        $preview = app(TelegramDatabaseOperator::class)->preview(new AiOperation(['payload' => ['plan' => $plan]]));
        $this->assertStringContainsString('product_media: 1', $preview);
        $this->assertStringContainsString('"approved_variant_id":null', $preview);
        $this->assertModelExists($product);
        $this->assertModelExists($variant);
    }

    public function test_group_creation_rolls_back_when_later_command_is_invalid(): void
    {
        $product = $this->product();
        $definition = AttributeDefinition::query()->create(['label' => 'RAM', 'data_type' => 'number', 'is_variant' => false]);
        $value = $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => '16', 'numeric_value' => 16]);
        $plan = $this->service()->prepare([
            ['action' => 'create', 'table' => 'attribute_groups', 'ref' => 'ram', 'values' => ['name' => 'RAM']],
            ['action' => 'update', 'table' => 'attribute_values', 'selection' => ['ids' => [$value->id]], 'values' => ['value' => 'nonsense']],
        ]);
        $this->rejected(fn () => $this->service()->execute($plan));
        $this->assertSame(0, AttributeGroup::query()->count());
        $this->assertSame('16', $value->fresh()->value);
    }

    public function test_delete_all_drafts_freezes_ids_and_cleans_media_without_deleting_audit(): void
    {
        $context = $this->update();
        $first = $this->draft($context);
        $second = $this->draft($context);
        $first->media()->create(['disk' => 'public', 'path' => 'product-drafts/'.$first->id.'/front.jpg', 'checksum' => str_repeat('1', 64)]);
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['all' => true]]], $context);
        $later = $this->draft($context);
        $this->assertSame(2, $plan['affected_count']);
        $this->assertTrue($plan['requires_confirmation']);
        $result = $this->service()->execute($plan, $context);
        $this->assertSame(2, $result['affected_count']);
        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
        $this->assertModelExists($later);
        $this->assertDatabaseCount('product_draft_media', 0);
        $this->assertDatabaseCount('ai_runs', 3);
        Queue::assertPushed(DeleteUnreferencedMedia::class, fn ($job): bool => $job->afterCommit === true && $job->path === 'product-drafts/'.$first->id.'/front.jpg');
    }

    public function test_draft_query_and_bulk_mutations_are_scoped_to_current_chat_and_owner(): void
    {
        $context = $this->update();
        $mine = $this->draft($context);
        $otherChat = $this->draft($this->update('5', '11'));
        $otherUser = $this->draft($this->update('6', '10'));
        $query = $this->service()->query('product_drafts', [], ['id', 'title'], 50, 0, $context);
        $this->assertSame(1, $query['total']);
        $this->assertSame($mine->id, $query['rows'][0]['id']);
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['ids' => [$otherChat->id, $otherUser->id]]]], $context);
        $this->assertSame(0, $plan['affected_count']);
        $this->service()->execute($plan, $context);
        $this->assertDatabaseCount('product_drafts', 3);
        $this->rejected(fn () => $this->service()->execute($plan, $this->update('6', '10')));
    }

    public function test_changed_row_or_new_dependent_invalidates_the_entire_confirmation(): void
    {
        $product = $this->product();
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'products', 'selection' => ['ids' => [$product->id]]]]);
        $product->variants()->create(['sku' => 'NEW-CHILD']);
        $this->rejected(fn () => $this->service()->execute($plan));
        $this->assertModelExists($product);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_queries_paginate_with_literal_filters_and_bounded_results(): void
    {
        AttributeGroup::query()->create(['name' => '100% RAM']);
        AttributeGroup::query()->create(['name' => '1000 RAM']);
        $query = $this->service()->query('attribute_groups', ['filters' => [['column' => 'name', 'operator' => 'contains', 'value' => '100%']]], ['id', 'name'], 9999);
        $this->assertSame(1, $query['total']);
        $this->assertSame(200, $query['limit']);
        $this->assertSame('100% RAM', $query['rows'][0]['name']);
        $this->rejected(fn () => $this->service()->query('users'));
        $this->rejected(fn () => $this->service()->query('brands', ['filters' => [['column' => 'name); DELETE FROM brands;--', 'value' => 'x']]]));
    }

    public function test_writes_reject_system_fields_proofs_invalid_types_and_wrong_references(): void
    {
        foreach ([['table' => 'users', 'values' => ['is_admin' => true]],
            ['table' => 'product_drafts', 'values' => ['specifications_reconciled_source_url' => 'https://example.com']],
            ['table' => 'attribute_groups', 'values' => ['highlight_in_filters' => 'arbitrary']],
            ['table' => 'ai_runs', 'values' => ['usage' => []]],
        ] as $command) {
            $this->rejected(fn () => $this->service()->prepare([['action' => 'update', 'selection' => ['all' => true], ...$command]]));
        }
        $this->rejected(fn () => $this->service()->prepare([
            ['action' => 'create', 'table' => 'brands', 'ref' => 'brand', 'values' => ['name' => 'Wrong ref']],
            ['action' => 'create', 'table' => 'attribute_definitions', 'values' => ['label' => 'RAM', 'attribute_group_id' => ['$ref' => 'brand.id']]],
        ]));
    }

    public function test_category_cycle_is_rejected_and_atomic_create_is_rolled_back(): void
    {
        $a = Category::query()->create(['name' => 'A']);
        $b = Category::query()->create(['name' => 'B', 'parent_id' => $a->id]);
        $plan = $this->service()->prepare([
            ['action' => 'create', 'table' => 'attribute_groups', 'values' => ['name' => 'Rollback']],
            ['action' => 'update', 'table' => 'categories', 'selection' => ['ids' => [$a->id]], 'values' => ['parent_id' => $b->id]],
        ]);
        $this->rejected(fn () => $this->service()->execute($plan));
        $this->assertNull($a->fresh()->parent_id);
        $this->assertDatabaseMissing('attribute_groups', ['name' => 'Rollback']);
    }

    public function test_composite_category_attribute_links_support_create_update_query_and_delete(): void
    {
        $attribute = AttributeDefinition::query()->create(['label' => 'RAM']);
        $category = Category::query()->firstOrFail();
        $this->service()->execute($this->service()->prepare([['action' => 'create', 'table' => 'attribute_definition_category',
            'values' => ['attribute_definition_id' => $attribute->id, 'category_id' => $category->id, 'is_default' => true]]]));
        $id = $attribute->id.':'.$category->id;
        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'attribute_definition_category',
            'selection' => ['ids' => [$id]], 'values' => ['is_required' => true]]]));
        $query = $this->service()->query('attribute_definition_category', ['ids' => [$id]]);
        $this->assertTrue($query['rows'][0]['is_required']);
        $this->service()->execute($this->service()->prepare([['action' => 'delete', 'table' => 'attribute_definition_category', 'selection' => ['ids' => [$id]]]]));
        $this->assertSame(0, DB::table('attribute_definition_category')->where('attribute_definition_id', $attribute->id)->count());
    }

    public function test_value_edit_recomputes_normalization_and_drops_stale_translation(): void
    {
        $product = $this->product();
        $attribute = AttributeDefinition::query()->create(['label' => 'Memory', 'data_type' => 'number', 'is_variant' => false]);
        $value = $product->attributes()->create(['attribute_definition_id' => $attribute->id, 'value' => '8', 'numeric_value' => 8, 'translations' => ['cs' => 'osm']]);
        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'attribute_values',
            'selection' => ['ids' => [$value->id]], 'values' => ['value' => '16']]]));
        $this->assertSame('16', $value->fresh()->normalized_value);
        $this->assertSame('16.0000', $value->fresh()->numeric_value);
        $this->assertNull($value->fresh()->translations);
    }

    public function test_variant_default_is_unique_and_populated_variant_cannot_move_to_another_product(): void
    {
        $product = $this->product();
        $first = $product->variants()->create(['sku' => 'ONE', 'is_default' => true]);
        $second = $product->variants()->create(['sku' => 'TWO']);
        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'product_variants', 'selection' => ['ids' => [$second->id]], 'values' => ['is_default' => true]]]));
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $definition = AttributeDefinition::query()->create(['label' => 'Memory', 'is_variant' => true]);
        $second->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => '16 GB']);
        $other = $this->product();
        $plan = $this->service()->prepare([['action' => 'update', 'table' => 'product_variants', 'selection' => ['ids' => [$second->id]], 'values' => ['product_id' => $other->id]]]);
        $this->rejected(fn () => $this->service()->execute($plan));
        $this->assertSame($product->id, $second->fresh()->product_id);
    }

    public function test_moving_an_empty_default_variant_restores_the_default_on_both_products(): void
    {
        $from = $this->product();
        $to = $this->product();
        $moving = $from->variants()->create(['sku' => 'MOVING', 'is_default' => true]);
        $remaining = $from->variants()->create(['sku' => 'REMAINING', 'is_default' => false]);
        $destinationDefault = $to->variants()->create(['sku' => 'DESTINATION', 'is_default' => true]);

        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'product_variants',
            'selection' => ['ids' => [$moving->id]], 'values' => ['product_id' => $to->id]]]));

        $this->assertSame($remaining->id, $from->fresh()->defaultVariant?->id);
        $this->assertSame($moving->id, $to->fresh()->defaultVariant?->id);
        $this->assertFalse($destinationDefault->fresh()->is_default);
        $this->assertSame(1, $from->variants()->where('is_default', true)->count());
        $this->assertSame(1, $to->variants()->where('is_default', true)->count());
    }

    public function test_approved_draft_reference_prevents_moving_an_otherwise_empty_variant(): void
    {
        $from = $this->product();
        $to = $this->product();
        $variant = $from->variants()->create(['sku' => 'APPROVED', 'is_default' => true]);
        $draft = $this->draft($this->update());
        $draft->update(['status' => 'approved', 'approved_product_id' => $from->id, 'approved_variant_id' => $variant->id]);
        $plan = $this->service()->prepare([['action' => 'update', 'table' => 'product_variants',
            'selection' => ['ids' => [$variant->id]], 'values' => ['product_id' => $to->id]]]);

        $this->rejected(fn () => $this->service()->execute($plan));

        $this->assertSame($from->id, $variant->fresh()->product_id);
        $this->assertSame($from->id, $draft->fresh()->approved_product_id);
        $this->assertSame($variant->id, $draft->fresh()->approved_variant_id);
        $this->assertTrue($variant->fresh()->is_default);
    }

    public function test_active_drafts_and_referenced_definition_deletion_fail_without_partial_changes(): void
    {
        $update = $this->update();
        $draft = $this->draft($update);
        $update->update(['status' => 'processing', 'processed_at' => null]);
        $this->rejected(fn () => $this->service()->prepare([['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['all' => true]]], $update));
        $this->assertModelExists($draft);
        $product = $this->product();
        $definition = AttributeDefinition::query()->create(['label' => 'Memory', 'is_variant' => false]);
        $product->attributes()->create(['attribute_definition_id' => $definition->id, 'value' => '16 GB']);
        $this->rejected(fn () => $this->service()->prepare([['action' => 'delete', 'table' => 'attribute_definitions', 'selection' => ['ids' => [$definition->id]]]]));
        $this->assertDatabaseCount('attribute_values', 1);
    }

    public function test_plain_draft_edit_invalidates_existing_reconciliation(): void
    {
        $update = $this->update();
        $draft = $this->draft($update);
        $draft->update(['primary_source_url' => 'https://example.com/item', 'specifications_reconciled_source_url' => 'https://example.com/item']);
        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'product_drafts',
            'selection' => ['ids' => [$draft->id]], 'values' => ['title' => 'Changed']]], $update), $update);
        $this->assertNull($draft->fresh()->specifications_reconciled_source_url);
    }

    public function test_creating_product_cannot_publish_and_creating_draft_uses_trusted_context(): void
    {
        $category = Category::query()->firstOrFail();
        $plan = $this->service()->prepare([['action' => 'create', 'table' => 'products', 'values' => ['category_id' => $category->id, 'title' => 'Safe']]]);
        $this->service()->execute($plan);
        $this->assertSame('draft', Product::query()->sole()->status);
        $this->assertFalse(Product::query()->sole()->is_active);
        $bad = $this->service()->prepare([['action' => 'create', 'table' => 'products', 'values' => ['category_id' => $category->id, 'title' => 'Unsafe', 'status' => 'published']]]);
        $this->rejected(fn () => $this->service()->execute($bad));
        $context = $this->update();
        $this->service()->execute($this->service()->prepare([['action' => 'create', 'table' => 'product_drafts', 'values' => ['title' => 'Manual operator draft']]], $context), $context);
        $draft = ProductDraft::query()->sole();
        $this->assertSame($context->id, $draft->telegram_update_id);
        $this->assertSame('5', $draft->requested_by_telegram_user_id);
        $this->assertSame('pending_review', $draft->status);
        $this->assertNull($draft->specifications_reconciled_source_url);
    }

    public function test_null_or_incomplete_selectors_never_turn_into_all_row_mutation(): void
    {
        $group = AttributeGroup::query()->create(['name' => 'Keep']);
        foreach ([[], ['all' => null], ['all' => false], ['id_from' => null], ['id_to' => null], ['filters' => []], ['filters' => null]] as $selection) {
            $this->rejected(fn () => $this->service()->prepare([['action' => 'delete', 'table' => 'attribute_groups', 'selection' => $selection]]));
        }
        $this->assertModelExists($group);
    }

    public function test_full_field_reader_returns_scoped_chunks_and_protects_secrets(): void
    {
        $context = $this->update();
        $draft = $this->draft($context);
        $text = str_repeat('Данные товара. ', 1000);
        $draft->update(['description' => $text]);
        $first = $this->service()->readValue('product_drafts', $draft->id, 'description', 0, 6000, $context);
        $second = $this->service()->readValue('product_drafts', $draft->id, 'description', 6000, 12000, $context);
        $this->assertSame($text, $first['text'].$second['text']);
        $this->assertTrue($first['has_more']);
        $this->assertFalse($second['has_more']);
        $this->rejected(fn () => $this->service()->readValue('product_drafts', $draft->id, 'description', 0, 6000, $this->update('6', '10')));
        $this->rejected(fn () => $this->service()->readValue('telegram_updates', $context->id, 'payload', 0, 6000, $context));
        $this->rejected(fn () => $this->service()->readValue('users', 1, 'password'));
    }

    public function test_queued_draft_work_prevents_deletion_even_after_source_update_completed(): void
    {
        $context = $this->update();
        $draft = $this->draft($context);
        Cache::put("draft-photo-actions:{$draft->id}:queued", true, 60);
        $this->rejected(fn () => $this->service()->prepare([['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['all' => true]]], $context));
        $this->assertModelExists($draft);
    }

    public function test_required_fk_null_is_rejected_and_draft_text_drops_its_stale_translation(): void
    {
        $product = $this->product();
        $this->rejected(fn () => $this->service()->prepare([['action' => 'update', 'table' => 'products', 'selection' => ['ids' => [$product->id]], 'values' => ['category_id' => null]]]));
        $context = $this->update();
        $draft = $this->draft($context);
        $draft->update(['translations' => ['cs' => ['title' => 'Old', 'description' => 'Old description']]]);
        $this->service()->execute($this->service()->prepare([['action' => 'update', 'table' => 'product_drafts', 'selection' => ['ids' => [$draft->id]], 'values' => ['title' => 'Updated']]], $context), $context);
        $this->assertArrayNotHasKey('title', $draft->fresh()->translations['cs']);
        $this->assertArrayNotHasKey('description', $draft->fresh()->translations['cs']);
        $this->rejected(fn () => $this->service()->prepare([['action' => 'update', 'table' => 'product_drafts', 'selection' => ['ids' => [$draft->id]], 'values' => ['specifications' => ['garbage']]]], $context));
    }

    public function test_new_page_evidence_invalidates_draft_deletion_instead_of_cascading_unreviewed_data(): void
    {
        $context = $this->update();
        $draft = $this->draft($context);
        $plan = $this->service()->prepare([['action' => 'delete', 'table' => 'product_drafts', 'selection' => ['ids' => [$draft->id]]]], $context);
        $evidence = ProductSourcePageEvidence::query()->create(['product_draft_id' => $draft->id, 'url_hash' => hash('sha256', 'https://example.com/item'),
            'url' => 'https://example.com/item', 'specification_text' => 'New evidence', 'captured_via' => 'http']);
        $this->rejected(fn () => $this->service()->execute($plan, $context));
        $this->assertModelExists($draft);
        $this->assertModelExists($evidence);
    }

    private function bulkDefinitions(int $count): array
    {
        $rows = [];
        for ($number = 1; $number <= $count; $number++) {
            $rows[] = ['key' => 'bulk-'.$number, 'label' => 'Bulk '.$number, 'data_type' => 'text', 'is_variant' => true];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('attribute_definitions')->insert($batch);
        }

        return AttributeDefinition::query()->where('key', 'like', 'bulk-%')->orderBy('id')->pluck('id')->all();
    }

    public function test_all_1001_composite_links_are_prepared_and_changed_without_subset_limits(): void
    {
        $ids = $this->bulkDefinitions(1001);
        $category = Category::query()->create(['name' => 'Large catalog']);
        DB::table('attribute_definition_category')->delete();
        foreach (array_chunk($ids, 100) as $batch) {
            DB::table('attribute_definition_category')->insert(array_map(fn ($id): array => [
                'attribute_definition_id' => $id, 'category_id' => $category->id,
            ], $batch));
        }
        $plan = $this->service()->prepare([['action' => 'update', 'table' => 'attribute_definition_category',
            'selection' => ['all' => true], 'values' => ['is_required' => true]]]);
        $this->assertSame(1001, $plan['affected_count']);
        $this->assertCount(1001, $plan['commands'][0]['ids']);
        $this->assertCount(5, $plan['commands'][0]['preview']);
        $this->assertSame(end($ids).':'.$category->id, $plan['commands'][0]['ids'][1000]);
        $result = $this->service()->execute($plan);
        $this->assertSame(1001, $result['affected_count']);
        $this->assertSame(1001, DB::table('attribute_definition_category')->where('is_required', true)->count());
        $deletion = $this->service()->prepare([['action' => 'delete', 'table' => 'attribute_definition_category', 'selection' => ['all' => true]]]);
        $this->assertSame(1001, $this->service()->execute($deletion)['affected_count']);
        $this->assertDatabaseCount('attribute_definition_category', 0);
    }

    public function test_validation_failure_at_row_1001_rolls_back_every_earlier_chunk_and_created_reference(): void
    {
        $ids = $this->bulkDefinitions(1001);
        $product = $this->product();
        $variant = $product->variants()->create(['sku' => 'LATE-ROW']);
        $variant->attributes()->create(['attribute_definition_id' => end($ids), 'value' => 'Text value']);
        $plan = $this->service()->prepare([
            ['action' => 'create', 'table' => 'attribute_groups', 'ref' => 'bulk', 'values' => ['name' => 'Must rollback']],
            ['action' => 'update', 'table' => 'attribute_definitions', 'selection' => ['ids' => $ids],
                'values' => ['data_type' => 'number', 'attribute_group_id' => ['$ref' => 'bulk.id']]],
        ]);
        $this->rejected(fn () => $this->service()->execute($plan));
        $this->assertSame(1001, AttributeDefinition::query()->whereIn('id', $ids)->where('data_type', 'text')->whereNull('attribute_group_id')->count());
        $this->assertDatabaseMissing('attribute_groups', ['name' => 'Must rollback']);
        $this->assertDatabaseCount('attribute_values', 1);
    }
}
