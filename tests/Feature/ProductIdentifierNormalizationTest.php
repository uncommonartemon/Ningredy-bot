<?php

namespace Tests\Feature;

use App\Jobs\StoreProductImages;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductIdentifierNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_identifier_arriving_with_its_explanation_is_stored_clean(): void
    {
        // Seen live on a published MacBook Air: research wrote the part number
        // for a human reader, and the whole sentence landed in the sku column.
        // Such a value can never match the same product's plain MC7A4LL/A from
        // another source, which is exactly what duplicate detection compares.
        $product = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers([
            ['key' => 'sku', 'name' => 'Retailer SKU', 'value' => 'MC7A4LL/A (US retailer / MFG part number for 15-inch M4 — 16 GB / 256 GB — Sky Blue)'],
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'XPS9350-5342GLD'],
        ]));

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('MC7A4LL/A', $variant->sku);
        // A hyphen inside a token is part of the identifier, never a separator.
        $this->assertSame('XPS9350-5342GLD', $variant->mpn);
    }

    public function test_every_prose_separator_is_cut_without_touching_real_identifiers(): void
    {
        $product = app(ProductDraftWorkflow::class)->approve($this->draftWithIdentifiers([
            ['key' => 'sku', 'name' => 'SKU', 'value' => 'SFG14-73-79D3, Silver'],
            ['key' => 'mpn', 'name' => 'MPN', 'value' => 'NX.KSGEK.002 — UK variant'],
            ['key' => 'gtin', 'name' => 'GTIN', 'value' => '4711121557880 [EAN-13]'],
        ]));

        $variant = $product->variants()->firstOrFail();
        $this->assertSame('SFG14-73-79D3', $variant->sku);
        $this->assertSame('NX.KSGEK.002', $variant->mpn);
        $this->assertSame('4711121557880', $variant->gtin);
    }

    /** @param array<int, array<string, string>> $specifications */
    private function draftWithIdentifiers(array $specifications): ProductDraft
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 90000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'Apple MacBook Air 15 M4',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'status' => 'completed',
            'prompt' => 'Apple MacBook Air 15 M4',
            'started_at' => now(),
        ]);

        Queue::fake([StoreProductImages::class]);

        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'Apple MacBook Air (15-inch, M4)',
            'brand' => 'Apple',
            'model' => 'MacBook Air 15 M4',
            'product_type' => 'laptop',
            'category' => 'laptops',
            'color' => 'Sky Blue',
            'description' => 'Тонкий и лёгкий ноутбук.',
            'specifications' => $specifications,
            'sources' => [],
            'image_urls' => [],
            'confidence' => 0.9,
            'status' => 'pending_review',
        ]);
        // approve() refuses a draft with no media, so give it one that clears
        // the category's own resolution minimum.
        $draft->media()->create([
            'disk' => 'public',
            'path' => 'drafts/'.$draft->id.'/photo.webp',
            'source_url' => 'https://example.com/photo.webp',
            'role' => 'primary',
            'mime_type' => 'image/webp',
            'width' => 1200,
            'height' => 1200,
            'file_size' => 2048,
            'checksum' => hash('sha256', 'photo-'.$draft->id),
            'verification_status' => 'verified',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $draft;
    }
}
