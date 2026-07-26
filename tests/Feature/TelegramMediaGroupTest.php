<?php

namespace Tests\Feature;

use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramMediaGroupTest extends TestCase
{
    public function test_it_sends_local_product_images_as_one_album_with_caption(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/primary.webp', 'first-image');
        Storage::disk('public')->put('products/1/secondary.webp', 'second-image');
        config()->set('services.telegram.bot_token', 'test-token');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        app(TelegramClient::class)->sendMediaGroupFiles('98765', [
            Storage::disk('public')->path('products/1/primary.webp'),
            Storage::disk('public')->path('products/1/secondary.webp'),
        ], 'Product description');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMediaGroup'));
        Http::assertSentCount(1);
    }

    public function test_valid_webp_is_converted_to_jpeg_for_telegram_photo_upload(): void
    {
        Storage::fake('public');
        $image = imagecreatetruecolor(100, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 40, 50));
        ob_start();
        imagewebp($image, null, 90);
        $webp = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put('drafts/1/primary.webp', $webp);
        config()->set('services.telegram.bot_token', 'test-token');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        app(TelegramClient::class)->sendPhotoFile(
            '98765',
            Storage::disk('public')->path('drafts/1/primary.webp'),
            'Draft',
        );

        Http::assertSent(function (Request $request): bool {
            $parts = collect($request->data())->keyBy('name');

            return str_ends_with($request->url(), '/sendPhoto')
                && str_ends_with((string) ($parts->get('photo')['filename'] ?? ''), '.jpg');
        });
    }

    public function test_one_local_image_is_sent_as_one_photo_post(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/1/primary.webp', 'first-image');
        config()->set('services.telegram.bot_token', 'test-token');
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        app(TelegramClient::class)->sendMediaGroupFiles('98765', [
            Storage::disk('public')->path('products/1/primary.webp'),
        ], 'Product description');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'));
        Http::assertSentCount(1);
    }
}
