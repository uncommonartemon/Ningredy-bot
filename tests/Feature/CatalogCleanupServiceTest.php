<?php

namespace Tests\Feature;

use App\Jobs\ContinueDraftGallerySearch;
use App\Jobs\DeleteUnreferencedMedia;
use App\Jobs\RunProductFormAssistant;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\AttributeDefinition;
use App\Models\AttributeGroup;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Maintenance\CatalogCleanupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.name' => 'Cleanup Admin']);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
    }

    private function actor(): User
    {
        return User::factory()->create(['name' => 'Cleanup Admin', 'is_admin' => true]);
    }

    private function service(): CatalogCleanupService
    {
        return app(CatalogCleanupService::class);
    }

    private function fixture(): array
    {
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $group = AttributeGroup::query()->create(['name' => 'Memory']);
        $attribute = AttributeDefinition::query()->create(['label' => 'Installed memory', 'attribute_group_id' => $group->id]);
        $product = Product::query()->create(['category_id' => $category->id, 'title' => 'Laptop', 'status' => 'published']);
        $variant = $product->variants()->create(['sku' => 'FIXTURE', 'is_default' => true]);
        $variant->attributes()->create(['attribute_definition_id' => $attribute->id, 'value' => '16 GB']);
        $productPath = "products/{$product->id}/front.jpg";
        Storage::disk('public')->put($productPath, 'catalog bytes');
        $product->media()->create(['product_variant_id' => $variant->id, 'disk' => 'public', 'path' => $productPath, 'verification_status' => 'verified']);
        $update = TelegramUpdate::query()->create(['update_id' => 654321, 'telegram_user_id' => '123', 'chat_id' => '456', 'payload' => [], 'status' => 'completed']);
        $run = AiRun::query()->create(['telegram_update_id' => $update->id, 'provider' => 'local', 'model' => 'fixture', 'prompt' => 'fixture', 'status' => 'completed', 'started_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $update->id, 'ai_run_id' => $run->id, 'requested_by_telegram_user_id' => '123',
            'title' => 'Draft', 'specifications' => [], 'sources' => [], 'image_urls' => [], 'approved_product_id' => $product->id, 'approved_variant_id' => $variant->id]);
        $draftPath = "drafts/{$draft->id}/front.jpg";
        Storage::disk('public')->put($draftPath, 'draft bytes');
        $draft->media()->create(['disk' => 'public', 'path' => $draftPath, 'checksum' => hash('sha256', 'draft bytes')]);
        $attempt = ProductSourceAttempt::query()->create(['telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'domain' => 'example.com', 'product_url' => 'https://example.com/item', 'actor' => 'server', 'phase' => 'gallery', 'action' => 'inspect', 'status' => 'completed']);
        ProductSourceDomain::query()->create(['domain' => 'example.com', 'agent_hint' => 'Persistent manual hint']);
        $recipe = ProductGalleryRecipe::query()->create(['domain' => 'example.com', 'path_pattern' => '*', 'status' => 'active']);
        AppSetting::put('test.secret', 'keep-this-value');

        return compact('category', 'group', 'attribute', 'product', 'variant', 'update', 'run', 'draft', 'attempt', 'recipe', 'productPath', 'draftPath');
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_preview_is_read_only_and_catalog_preset_keeps_drafts_settings_and_recipe_memory(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('catalog', [], $actor);
        $this->assertSame(1, $plan['counts']['products']);
        $this->assertSame(1, $plan['counts']['product_media']);
        $this->assertSame(1, $plan['effects']['draft_catalog_links_removed']);
        $this->assertSame([], $plan['blockers']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('ai_operations', 0);
        Queue::assertNothingPushed();
        $result = $this->service()->execute($plan, $actor);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['deleted']['products']);
        $this->assertDatabaseCount('products', 0);
        $this->assertModelExists($data['draft']);
        $this->assertNull($data['draft']->fresh()->approved_product_id);
        $this->assertNull($data['draft']->fresh()->approved_variant_id);
        $this->assertModelExists($data['attribute']);
        $this->assertModelExists($data['recipe']);
        $this->assertSame('keep-this-value', AppSetting::valueFor('test.secret'));
        Storage::disk('public')->assertExists($data['draftPath']);
        Queue::assertPushed(DeleteUnreferencedMedia::class, fn ($job): bool => $job->afterCommit === true && $job->path === $data['productPath']);
        $this->assertDatabaseHas('ai_operations', ['id' => $result['operation_id'], 'action' => 'admin_cleanup', 'status' => 'completed', 'telegram_update_id' => null]);
        $this->assertSame($result, $this->service()->execute($plan, $actor));
        $this->assertDatabaseCount('ai_operations', 1);
    }

    public function test_drafts_preset_keeps_catalog_and_audit_and_ids_are_not_reused(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('drafts', [], $actor);
        $this->service()->execute($plan, $actor);
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertDatabaseCount('product_draft_media', 0);
        $this->assertModelExists($data['product']);
        $this->assertModelExists($data['run']);
        $this->assertModelExists($data['update']);
        $this->assertModelExists($data['attempt']);
        $this->assertNull($data['attempt']->fresh()->product_draft_id);
        Queue::assertPushed(DeleteUnreferencedMedia::class, fn ($job): bool => $job->path === $data['draftPath']);
        $next = ProductDraft::query()->create(['telegram_update_id' => $data['update']->id, 'ai_run_id' => $data['run']->id,
            'requested_by_telegram_user_id' => '123', 'title' => 'Next', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        $this->assertGreaterThan($data['draft']->id, $next->id);
    }

    public function test_test_data_default_preserves_dictionaries_recipes_bans_and_unrelated_cache(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        Cache::put('unrelated-lock-marker', 'keep', 600);
        DB::table('agent_conversations')->insert(['id' => 'conversation', 'title' => 'Test']);
        DB::table('telegram_chat_states')->insert(['chat_id' => '456', 'conversation_id' => 'conversation']);
        $plan = $this->service()->prepare('test_data', [], $actor);
        $this->assertArrayNotHasKey('attribute_definitions', $plan['counts']);
        $this->assertArrayNotHasKey('product_gallery_recipes', $plan['counts']);
        $this->service()->execute($plan, $actor);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseCount('telegram_chat_states', 0);
        $this->assertModelExists($data['attribute']);
        $this->assertModelExists($data['group']);
        $this->assertModelExists($data['category']);
        $this->assertModelExists($data['recipe']);
        $this->assertSame('keep', Cache::get('unrelated-lock-marker'));
        $this->assertDatabaseHas('product_source_domains', ['domain' => 'example.com', 'agent_hint' => 'Persistent manual hint']);
        $this->assertModelExists($actor);
    }

    public function test_explicit_dictionary_and_recipe_reset_preserves_blocked_domains_and_audits(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $blocked = ProductGalleryRecipe::query()->create(['domain' => 'blocked.example', 'path_pattern' => '*', 'source_blocked' => true,
            'source_block_reason' => 'Operator ban', 'status' => 'disabled']);
        $version = ProductGalleryRecipeVersion::query()->create(['product_gallery_recipe_id' => $data['recipe']->id, 'domain' => 'example.com',
            'product_url' => 'https://example.com/item', 'trigger' => 'test', 'provider' => 'local', 'model' => 'fixture', 'status' => 'failed']);
        $data['attempt']->update(['product_gallery_recipe_version_id' => $version->id]);
        $plan = $this->service()->prepare('test_data', ['dictionaries' => true, 'recipes_history' => true], $actor);
        $this->assertSame(1, $plan['counts']['product_gallery_recipes']);
        $this->assertSame(1, $plan['effects']['preserved_blocked_domains']);
        $this->service()->execute($plan, $actor);
        foreach (['attribute_values', 'attribute_definitions', 'attribute_groups', 'categories', 'brands', 'product_gallery_recipe_versions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertModelExists($blocked);
        $this->assertTrue($blocked->fresh()->source_blocked);
        $this->assertModelExists($data['attempt']);
        $this->assertNull($data['attempt']->fresh()->product_gallery_recipe_version_id);
        $this->assertModelExists($data['run']);
    }

    public function test_attribute_and_group_presets_have_distinct_explicit_effects(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $groupPlan = $this->service()->prepare('attribute_groups', [], $actor);
        $this->assertSame(1, $groupPlan['effects']['ungrouped_attributes']);
        $this->service()->execute($groupPlan, $actor);
        $this->assertModelExists($data['attribute']);
        $this->assertNull($data['attribute']->fresh()->attribute_group_id);
        $attributePlan = $this->service()->prepare('attributes', [], $actor);
        $this->assertSame(1, $attributePlan['counts']['attribute_values']);
        $this->service()->execute($attributePlan, $actor);
        $this->assertDatabaseCount('attribute_definitions', 0);
        $this->assertDatabaseCount('attribute_values', 0);
        $this->assertModelExists($data['product']);
        $this->assertModelExists($data['draft']);
    }

    public function test_new_rows_or_changed_rows_make_the_plan_stale_and_failure_audit_survives(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('catalog', [], $actor);
        $data['product']->variants()->create(['sku' => 'NEW-AFTER-PREVIEW']);
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->assertModelExists($data['product']);
        $this->assertDatabaseCount('product_media', 1);
        Queue::assertNothingPushed();
        $operation = AiOperation::query()->where('action', 'admin_cleanup')->sole();
        $this->assertSame('failed', $operation->status);
        $this->assertTrue($operation->result['rolled_back']);
    }

    public function test_plan_signature_actor_expiry_and_fresh_admin_access_are_enforced(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('drafts', [], $actor);
        $tampered = $plan;
        $tampered['preset'] = 'test_data';
        $this->rejected(fn () => $this->service()->execute($tampered, $actor));
        $second = User::factory()->create(['name' => 'Other Cleanup Admin', 'is_admin' => true]);
        config(['services.admin.name' => 'Other Cleanup Admin']);
        $this->rejected(fn () => $this->service()->execute($plan, $second));
        config(['services.admin.name' => 'Cleanup Admin']);
        $this->travel(16)->minutes();
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->travelBack();
        $actor->update(['is_admin' => false]);
        try {
            $this->service()->execute($plan, $actor);
            $this->fail('A revoked administrator must not execute cleanup.');
        } catch (AuthorizationException) {
            $this->assertModelExists($data['draft']);
        }
    }

    public function test_active_admin_ai_and_queued_work_are_blocked_without_deleting_jobs(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $data['run']->update(['status' => 'queued', 'telegram_update_id' => null, 'activity' => ['kind' => 'product_form_assistant']]);
        DB::table('jobs')->insert(['queue' => 'admin-ai', 'payload' => json_encode(['displayName' => RunProductFormAssistant::class]),
            'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $plan = $this->service()->prepare('test_data', [], $actor);
        $this->assertNotEmpty($plan['blockers']);
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->assertModelExists($data['product']);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('ai_operations', ['action' => 'admin_cleanup', 'status' => 'failed']);
    }

    public function test_queued_safe_file_cleanup_does_not_block_the_next_cleanup(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => json_encode(['displayName' => DeleteUnreferencedMedia::class,
            'data' => ['commandName' => DeleteUnreferencedMedia::class]]), 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp()]);
        $plan = $this->service()->prepare('drafts', [], $actor);
        $this->assertSame([], $plan['blockers']);
        $this->service()->execute($plan, $actor);
        $this->assertModelMissing($data['draft']);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_work_starting_after_preview_and_draft_markers_block_execution(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('catalog', [], $actor);
        Cache::put("draft-gallery-continue:{$data['draft']->id}:queued", true, 600);
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->assertModelExists($data['product']);
        $this->assertTrue(Cache::has("draft-gallery-continue:{$data['draft']->id}:queued"));
    }

    public function test_failure_after_earlier_model_deletions_rolls_back_rows_and_preserves_media(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('catalog', [], $actor);
        Product::deleting(function (): void {
            throw ValidationException::withMessages(['product' => 'Simulated model deletion refusal.']);
        });
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->assertModelExists($data['product']);
        $this->assertDatabaseCount('product_media', 1);
        $this->assertDatabaseCount('product_variants', 1);
        Storage::disk('public')->assertExists($data['productPath']);
        $this->assertDatabaseHas('ai_operations', ['action' => 'admin_cleanup', 'status' => 'failed']);
    }

    public function test_a_model_observer_returning_false_cannot_produce_a_false_success(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $plan = $this->service()->prepare('catalog', [], $actor);
        Product::deleting(fn (): bool => false);
        $this->rejected(fn () => $this->service()->execute($plan, $actor));
        $this->assertModelExists($data['product']);
        $this->assertDatabaseCount('product_media', 1);
        $this->assertDatabaseHas('ai_operations', ['action' => 'admin_cleanup', 'status' => 'failed']);
    }

    public function test_historical_running_state_without_live_work_does_not_block_or_rewrite_audits(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $old = now()->subDays(10);
        $data['run']->forceFill(['status' => 'running', 'created_at' => $old, 'started_at' => $old, 'updated_at' => $old, 'last_activity_at' => $old])->saveQuietly();
        $data['update']->forceFill(['status' => 'processing', 'processed_at' => null, 'created_at' => $old, 'updated_at' => $old])->saveQuietly();
        $data['draft']->forceFill(['gallery_status' => 'running', 'created_at' => $old, 'updated_at' => $old])->saveQuietly();
        $audit = AiOperation::query()->create(['tool' => 'Old fixture', 'action' => 'old_pending_operation', 'status' => 'pending',
            'created_at' => $old, 'updated_at' => $old]);
        $audit->forceFill(['created_at' => $old, 'updated_at' => $old])->saveQuietly();
        $beforeRun = $data['run']->fresh()->getAttributes();
        $beforeUpdate = $data['update']->fresh()->getAttributes();
        $beforeAudit = $audit->fresh()->getAttributes();
        $plan = $this->service()->prepare('test_data', [], $actor);
        $this->assertSame([], $plan['blockers']);
        $this->service()->execute($plan, $actor);
        $this->assertSame($beforeRun, $data['run']->fresh()->getAttributes());
        $this->assertSame($beforeUpdate, $data['update']->fresh()->getAttributes());
        $this->assertSame($beforeAudit, $audit->fresh()->getAttributes());
    }

    public function test_a_quiet_30_minute_ai_call_and_an_old_run_with_a_fresh_heartbeat_both_block(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $quietStart = now()->subMinutes(30);
        $data['run']->forceFill(['status' => 'running', 'created_at' => $quietStart, 'started_at' => $quietStart,
            'updated_at' => $quietStart, 'last_activity_at' => null])->saveQuietly();
        $this->assertNotEmpty($this->service()->prepare('catalog', [], $actor)['blockers']);
        $old = now()->subDays(10);
        $data['run']->forceFill(['created_at' => $old, 'started_at' => $old, 'updated_at' => $old, 'last_activity_at' => now()])->saveQuietly();
        $this->assertNotEmpty($this->service()->prepare('catalog', [], $actor)['blockers']);
        $this->assertModelExists($data['product']);
    }

    public function test_ancient_queue_records_and_old_draft_state_with_a_live_marker_are_still_absolute_vetoes(): void
    {
        $actor = $this->actor();
        $data = $this->fixture();
        $old = now()->subDays(10);
        $data['draft']->forceFill(['gallery_status' => 'running', 'created_at' => $old, 'updated_at' => $old])->saveQuietly();
        Cache::put("draft-gallery-continue:{$data['draft']->id}:queued", true, 600);
        $this->assertNotEmpty($this->service()->prepare('catalog', [], $actor)['blockers']);
        Cache::forget("draft-gallery-continue:{$data['draft']->id}:queued");
        $this->assertSame([], $this->service()->prepare('catalog', [], $actor)['blockers']);
        DB::table('jobs')->insert(['queue' => 'media', 'payload' => json_encode(['displayName' => ContinueDraftGallerySearch::class]),
            'attempts' => 1, 'reserved_at' => $old->getTimestamp(), 'available_at' => $old->getTimestamp(), 'created_at' => $old->getTimestamp()]);
        $this->assertNotEmpty($this->service()->prepare('catalog', [], $actor)['blockers']);
        $this->assertDatabaseCount('jobs', 1);
    }
}
