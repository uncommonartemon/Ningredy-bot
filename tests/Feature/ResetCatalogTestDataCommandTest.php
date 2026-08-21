<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductSourceAttempt;
use App\Models\ProductSourceDomain;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetCatalogTestDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_test_results_without_reusing_draft_ids_or_deleting_settings_and_audits(): void
    {
        AppSetting::put('ai.minimum_image_side', '700');
        $update = TelegramUpdate::query()->create([
            'update_id' => 900001,
            'telegram_user_id' => '1',
            'chat_id' => '100',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => 'completed',
            'prompt' => 'test',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = $this->draft($update, $run);
        ProductGalleryRecipe::query()->create([
            'domain' => 'example.com',
            'path_pattern' => '*',
            'status' => 'active',
        ]);
        ProductSourceDomain::query()->updateOrCreate(
            ['domain' => 'example.com'],
            ['agent_hint' => 'Persistent domain setting.'],
        );
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $update->id,
            'product_draft_id' => $draft->id,
            'domain' => 'example.com',
            'product_url' => 'https://example.com/product',
            'actor' => 'playwright',
            'phase' => 'gallery_training',
            'action' => 'inspect',
            'status' => 'completed',
        ]);

        $this->artisan('catalog:reset-test-data', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('product_drafts', 0);
        $this->assertDatabaseCount('product_gallery_recipes', 0);
        $this->assertDatabaseHas('product_source_domains', [
            'domain' => 'example.com',
            'agent_hint' => 'Persistent domain setting.',
        ]);
        $this->assertSame('700', AppSetting::valueFor('ai.minimum_image_side'));
        $this->assertDatabaseHas('ai_runs', ['id' => $run->id]);
        $this->assertDatabaseHas('product_source_attempts', [
            'telegram_update_id' => $update->id,
            'product_draft_id' => null,
            'action' => 'inspect',
        ]);

        $newDraft = $this->draft($update, $run);
        $this->assertGreaterThan($draft->id, $newDraft->id);
    }

    private function draft(TelegramUpdate $update, AiRun $run): ProductDraft
    {
        return ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'status' => 'pending_review',
            'requested_by_telegram_user_id' => '1',
            'title' => 'Test product',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
            'confidence' => 1,
        ]);
    }
}
