<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use Illuminate\Support\Facades\Storage;

class DraftTelegramPresenter
{
    public function sendReview(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $usageFootnote = '',
    ): void {
        $draft->load('media');
        $paths = $draft->media
            ->sortBy([
                ['is_primary', 'desc'],
                ['sort_order', 'asc'],
            ])
            ->filter(fn ($media): bool => filled($media->disk) && filled($media->path))
            ->map(fn ($media): string => Storage::disk($media->disk)->path($media->path))
            ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
            ->take(10)
            ->values()
            ->all();

        if ($paths !== []) {
            $telegram->sendMediaGroupFiles($chatId, $paths, $this->caption($draft, $usageFootnote));
        } else {
            $telegram->sendMessage($chatId, $this->caption($draft, $usageFootnote));
        }

        $this->sendControls($telegram, $chatId, $draft);
    }

    public function sendControls(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        return $telegram->sendMessage(
            $chatId,
            "Итого: черновик #{$draft->id}\nВыберите действие:",
            $this->controlMarkup($draft),
        );
    }

    public function sendPhotoSelection(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $action = 'enhance',
    ): array {
        $labels = [
            'enhance' => 'улучшить',
            'replace' => 'заменить',
            'delete' => 'удалить',
        ];
        $verb = $labels[$action] ?? $labels['enhance'];
        $buttons = $draft->media()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->take(10)
            ->get()
            ->values()
            ->map(fn ($media, int $index): array => [
                'text' => $this->numberEmoji($index + 1),
                'callback_data' => "draft:{$action}-photo:{$draft->id}:{$media->id}",
            ])
            ->chunk(5)
            ->map(fn ($row): array => $row->values()->all())
            ->values()
            ->all();
        $buttons[] = [[
            'text' => '← Назад',
            'callback_data' => "draft:review:{$draft->id}",
        ]];

        return $telegram->sendMessage(
            $chatId,
            "Какое фото черновика #{$draft->id} {$verb}?\nНомер соответствует позиции в альбоме.",
            ['inline_keyboard' => $buttons],
        );
    }

    private function controlMarkup(ProductDraft $draft): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Добавить', 'callback_data' => "draft:add:{$draft->id}"],
                    ['text' => '✖ Отменить', 'callback_data' => "draft:reject:{$draft->id}"],
                ],
                [
                    ['text' => '✨ Улучшить', 'callback_data' => "draft:enhance:{$draft->id}"],
                    ['text' => '🔄 Заменить', 'callback_data' => "draft:replace:{$draft->id}"],
                ],
                [
                    ['text' => '🗑 Удалить фото', 'callback_data' => "draft:delete:{$draft->id}"],
                ],
            ],
        ];
    }

    private function caption(ProductDraft $draft, string $usageFootnote): string
    {
        $specifications = collect($draft->specifications)->take(6)
            ->map(fn (array $item): string => "• {$item['name']}: {$item['value']}")
            ->implode("\n");
        $galleryCount = $draft->media()->count();
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $footer = implode("\n", array_filter([
            "📷 Фото: {$galleryCount}",
            $primarySourceUrl !== '' ? "🔗 Источник: {$primarySourceUrl}" : null,
        ]));
        $header = implode("\n", array_filter([
            "Черновик #{$draft->id} готов к добавлению",
            $draft->title,
            implode(' · ', array_filter([$draft->brand, $draft->model, $draft->color])),
        ]));
        $fixed = $header."\n\n".$footer.$usageFootnote;
        $available = max(0, 1024 - mb_strlen($fixed) - 8);
        $body = implode("\n\n", array_filter([
            mb_substr((string) $draft->description, 0, (int) floor($available * 0.55)),
            $specifications !== '' ? "Характеристики:\n".$specifications : null,
        ]));

        return mb_substr($header."\n\n".mb_substr($body, 0, $available)."\n\n".$footer.$usageFootnote, 0, 1024);
    }

    private function numberEmoji(int $number): string
    {
        return match ($number) {
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            5 => '5️⃣',
            6 => '6️⃣',
            7 => '7️⃣',
            8 => '8️⃣',
            9 => '9️⃣',
            10 => '🔟',
            default => (string) $number,
        };
    }
}
