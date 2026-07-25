<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\ProductVariant;
use App\Services\Ai\AiUsageReporter;
use App\Services\Products\ProductImageStorage;
use App\Services\Products\ProductPhotoManager;
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
        /** @var array<int, int> */
        public array $replaceMediaIds = [],
    ) {
        $this->onQueue('media');
    }

    public function handle(ProductImageStorage $storage, TelegramClient $telegram): void
    {
        // Decoding several full-size candidate images into GD bitmaps in the
        // same pass can exceed the default 128M CLI limit even after
        // filtering absurdly large ones; give this job more headroom so a
        // memory spike fails the job cleanly instead of killing the worker.
        ini_set('memory_limit', '512M');

        $product = Product::query()->find($this->productId);
        $variant = ProductVariant::query()->find($this->variantId);
        $draft = ProductDraft::query()->with('telegramUpdate')->find($this->draftId);

        if (! $product || ! $variant || ! $draft) {
            return;
        }

        $searchStartedAt = now();
        $stored = $storage->store($product, $variant, $draft, $this->replaceMediaIds);

        if ($this->replaceMediaIds !== []) {
            app(ProductPhotoManager::class)->completeRefind($product, $this->replaceMediaIds, $stored);
            $product->unsetRelation('media');
        }

        $chatId = $draft->telegramUpdate?->chat_id;
        $usageFootnote = $draft->telegram_update_id
            ? $this->usageFootnote($draft->telegram_update_id, $searchStartedAt)
            : '';

        if ($chatId) {
            try {
                if ($this->sendProductPost($telegram, $chatId, $product, $usageFootnote)) {
                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($chatId) {
            $media = $stored > 0
                ? $product->media()
                    ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->first()
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
                : 'Подходящие фото товара не найдены. Товар сохранён без изображения; напишите "найди фото заново" или добавьте вручную в Filament.';
            $this->notify($telegram, $chatId, $message.$usageFootnote);
        }
    }

    private function usageFootnote(int $telegramUpdateId, \DateTimeInterface $since): string
    {
        $usage = app(AiUsageReporter::class)->forTelegramUpdate($telegramUpdateId, $since);
        $tokens = (int) ($usage['tokens']['total'] ?? 0);

        if ($tokens <= 0) {
            return '';
        }

        $line = "\n\n🔢 Токены на поиск фото: ".number_format($tokens, 0, '.', ' ');

        if ($usage['estimated_cost_usd'] !== null) {
            $line .= sprintf(' (~$%s)', number_format((float) $usage['estimated_cost_usd'], 4));
        }

        return $line;
    }

    public function failed(?Throwable $exception): void
    {
        $draft = ProductDraft::query()->with('telegramUpdate')->find($this->draftId);
        $chatId = $draft?->telegramUpdate?->chat_id;

        if ($chatId) {
            $this->notify(
                app(TelegramClient::class),
                $chatId,
                'Не удалось обработать фотографии после повторной попытки. Товар сохранён; напишите "найди фото заново" или добавьте вручную в Filament.',
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

    private function sendProductPost(TelegramClient $telegram, string $chatId, Product $product, string $usageFootnote = ''): bool
    {
        $media = $product->media()
            ->where('type', 'image')
            ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($item): bool => filled($item->disk) && filled($item->path))
            ->filter(fn ($item): bool => is_file(Storage::disk($item->disk)->path($item->path))
                && is_readable(Storage::disk($item->disk)->path($item->path)))
            ->take(10)
            ->values();

        if ($media->isEmpty()) {
            return false;
        }

        $paths = $media->map(fn ($item): string => Storage::disk($item->disk)->path($item->path))->all();
        $caption = $this->caption($product, $media->pluck('source_url')->filter()->all()).$usageFootnote;
        $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
        // sendMediaGroup has no reply_markup support, so the "redo" action
        // has to be a short follow-up message instead of an inline button
        // on the album itself.
        $telegram->sendMessage($chatId, 'Фото не подошли?', [
            'inline_keyboard' => [[
                ['text' => '🔄 Найти фото заново', 'callback_data' => "photos:refind:{$product->id}"],
            ]],
        ]);

        return true;
    }

    /** @param array<int, string> $photoSourceUrls */
    private function caption(Product $product, array $photoSourceUrls = []): string
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
        $sourcesLine = $this->photoSourcesLine($photoSourceUrls);
        $footer .= $sourcesLine !== '' ? "\n\n{$sourcesLine}" : '';
        $description = trim((string) $product->description);
        $available = max(0, 1024 - mb_strlen($header.$footer) - 4);

        return $header.($description !== '' ? "\n\n".mb_substr($description, 0, $available) : '').$footer;
    }

    /** @param array<int, string> $sourceUrls */
    private function photoSourcesLine(array $sourceUrls): string
    {
        $hosts = collect($sourceUrls)
            ->map(fn (string $url): string => (string) parse_url($url, PHP_URL_HOST))
            ->filter()
            ->unique()
            ->values();

        return $hosts->isEmpty() ? '' : '📷 Фото с: '.$hosts->implode(', ');
    }
}
