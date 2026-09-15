<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductImageVisionAgent;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductImageVisionVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImageVisionVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shared_chassis_code_alone_does_not_grant_source_identity_confirmed(): void
    {
        // A candidate whose page only names the shared "LOQ 15IRX10" chassis
        // code - not the specific 83JE0013US sku - used to still earn
        // source_identity_confirmed here via the broad supportsSource()
        // check, independent of whatever ProductImageStorage's own (already
        // strict) gate had decided. With exact_match left false by Vision
        // itself, that flag is the only thing that could let this candidate
        // qualify - so it must not, on this evidence alone.
        $update = TelegramUpdate::query()->create([
            'update_id' => 460001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]);
        $draft = new ProductDraft([
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'color' => 'Luna Grey',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', $update);

        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => false,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 90,
                'reason' => 'Same chassis, sku not confirmed by the model alone.',
            ])->all(),
        ])->preventStrayPrompts();

        $candidates = [[
            'image' => $this->jpeg(),
            'source_url' => 'https://shop.example/loq-15irx10-other-config',
            'page_source_context' => [
                'title' => 'Lenovo LOQ 15IRX10 Gaming Laptop - Other Configuration',
                'url' => 'https://shop.example/loq-15irx10-other-config',
            ],
        ]];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $candidates, 4);

        $this->assertSame(
            [],
            $selected,
            'A shared chassis code alone must not stand in for source_identity_confirmed here - the candidate has no other way to qualify.',
        );
    }

    public function test_the_exact_sku_still_grants_source_identity_confirmed(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 461001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'completed', 'text' => 'Lenovo LOQ 15 - Luna Grey ищи',
        ]);
        $draft = new ProductDraft([
            'telegram_update_id' => $update->id,
            'title' => 'Lenovo LOQ 15IRX10 (83JE0013US) - Luna Grey',
            'brand' => 'Lenovo',
            'model' => 'LOQ 15IRX10 (83JE0013US)',
            'color' => 'Luna Grey',
            'specifications' => [
                ['key' => 'sku', 'name' => 'SKU', 'value' => '83JE0013US'],
            ],
        ]);
        $draft->setRelation('telegramUpdate', $update);

        ProductImageVisionAgent::fake(fn (string $prompt, $attachments): array => [
            'images' => $attachments->keys()->map(fn (int $index): array => [
                'index' => $index + 1,
                'exact_match' => false,
                'color_match' => true,
                'publishable' => true,
                'kind' => 'product',
                'view' => 'front',
                'gallery_rank' => $index + 1,
                'score' => 90,
                'reason' => 'Exact sku confirmed by the page.',
            ])->all(),
        ])->preventStrayPrompts();

        $candidates = [[
            'image' => $this->jpeg(),
            'source_url' => 'https://shop.example/loq-15irx10-83je0013us',
            'page_source_context' => [
                'title' => 'Lenovo LOQ 15IRX10 83JE0013US',
                'url' => 'https://shop.example/loq-15irx10-83je0013us',
            ],
        ]];

        $selected = app(ProductImageVisionVerifier::class)->select($draft, $candidates, 4);

        $this->assertCount(1, $selected, 'The exact sku on the page must still qualify the candidate.');
    }

    private function jpeg(): \GdImage
    {
        $image = imagecreatetruecolor(32, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        return $image;
    }
}
