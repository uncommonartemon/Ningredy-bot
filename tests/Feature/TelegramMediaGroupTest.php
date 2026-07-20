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
