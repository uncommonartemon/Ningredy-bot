<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageCandidateDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImageDiscoveryHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_continuation_prompt_remembers_empty_searches_and_blocked_sources(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 900001, 'telegram_user_id' => '1', 'chat_id' => '100',
            'text' => 'Acer Nitro V 15 ANV15-51 16 GB 512 GB', 'payload' => [], 'status' => 'processing',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id, 'provider' => 'fake', 'model' => 'fake',
            'status' => 'completed', 'prompt' => '[product-image-discovery:v8] previous search',
            'started_at' => now(), 'completed_at' => now(),
            'response' => ['sources' => [], 'image_urls' => [], 'page_urls' => [],
                'queries_tried' => ['ANV15-51-52U3 Mexico tienda']],
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '1', 'status' => 'pending_review',
            'title' => 'Acer Nitro V 15', 'specifications' => [], 'sources' => [], 'image_urls' => [], 'confidence' => 1,
        ]);
        ProductSourceAttempt::query()->create([
            'telegram_update_id' => $update->id, 'product_draft_id' => $draft->id,
            'product_url' => 'https://blocked.example/nitro', 'domain' => 'blocked.example',
            'actor' => 'playwright', 'phase' => 'gallery_preflight',
            'action' => 'classify_gallery', 'status' => 'completed', 'decision' => 'blocked',
        ]);
        $method = new \ReflectionMethod(ProductImageCandidateDiscovery::class, 'prompt');
        $prompt = $method->invoke(app(ProductImageCandidateDiscovery::class), $draft, [], [], 1, $update->id);
        $this->assertStringContainsString('"returned_page_urls":[]', $prompt);
        $this->assertStringContainsString('"returned_image_count":0', $prompt);
        $this->assertStringContainsString('https://blocked.example/nitro', $prompt);
        $this->assertStringContainsString('"decision":"blocked"', $prompt);
        $this->assertStringContainsString('ANV15-51-52U3 Mexico tienda', $prompt);
        $this->assertStringContainsString('change your search queries', $prompt);
        $this->assertStringContainsString('whole-card offer adoption', $prompt);
    }
}
