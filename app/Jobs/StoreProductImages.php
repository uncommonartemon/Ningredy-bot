<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Services\Products\ProductImageStorage;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StoreProductImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 180];

    public function __construct(
        public int $productId,
        public int $variantId,
        public int $draftId,
    ) {
        $this->onQueue('media');
    }

    public function handle(ProductImageStorage $storage, TelegramClient $telegram): void
    {
        $product = Product::query()->find($this->productId);
        $variant = ProductVariant::query()->find($this->variantId);
        $draft = ProductDraft::query()->with('telegramUpdate')->find($this->draftId);

        if (! $product || ! $variant || ! $draft) {
            return;
        }

        $stored = $storage->store($product, $variant, $draft);
        $chatId = $draft->telegramUpdate?->chat_id;

        if ($chatId) {
            try {
                if ($this->sendProductPost($telegram, $chatId, $product)) {
                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($chatId) {
            $media = $stored > 0
                ? $product->media()->whereIn('verification_status', ['verified', 'manual'])->latest('id')->first()
                : null;

            if ($media?->disk && $media?->path) {
                try {
                    $telegram->sendPhotoFile(
                        $chatId,
                        Storage::disk($media->disk)->path($media->path),
                        $product->title.' - Vision verified',
                    );
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $message = $stored > 0
                ? "Фото проверены Vision и сохранены: {$stored}."
                : 'Подходящие фото товара не найдены. Товар сохранён без изображения; фото можно добавить вручную в Filament.';
            $this->notify($telegram, $chatId, $message);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $draft = ProductDraft::query()->with('telegramUpdate')->find($this->draftId);
        $chatId = $draft?->telegramUpdate?->chat_id;

        if ($chatId) {
            $this->notify(
                app(TelegramClient::class),
                $chatId,
                'Не удалось обработать фотографии после повторной попытки. Товар сохранён; фото можно добавить вручную в Filament.',
            );
        }
    }

    private function notify(TelegramClient $telegram, string $chatId, string $message): void
    {
        try {
            $telegram->sendMessage($chatId, $message);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sendProductPost(TelegramClient $telegram, string $chatId, Product $product): bool
    {
        $paths = $product->media()
            ->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'manual'])
            ->get()
            ->filter(fn ($media): bool => filled($media->disk) && filled($media->path))
            ->map(fn ($media): string => Storage::disk($media->disk)->path($media->path))
            ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
            ->take(10)
            ->values()
            ->all();

        if ($paths === []) {
            return false;
        }

        $telegram->sendMediaGroupFiles($chatId, $paths, $this->caption($product));

        return true;
    }

    private function caption(Product $product): string
    {
        $product->loadMissing('brand');
        $identity = collect([$product->brand?->name, $product->model])->filter()->unique()->implode(' · ');
        $header = "✅ Товар #{$product->id} добавлен в каталог\n\n{$product->title}";

        if ($identity !== '') {
            $header .= "\n{$identity}";
        }

        $publicUrl = rtrim((string) AppSetting::valueFor(
            AppSetting::TELEGRAM_PROXY_URL,
            (string) config('services.telegram.proxy_url'),
        ), '/');
        $footer = $publicUrl !== '' ? "\n\n{$publicUrl}/products/{$product->slug}" : '';
        $description = trim((string) $product->description);
        $available = max(0, 1024 - mb_strlen($header.$footer) - 4);

        return $header.($description !== '' ? "\n\n".mb_substr($description, 0, $available) : '').$footer;
    }
}
