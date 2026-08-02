<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DraftTelegramPresenter
{
    public function __construct(
        private readonly DraftTelegramMessageLifecycle $messageLifecycle,
    ) {}

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

        $caption = $this->caption($draft, $usageFootnote);

        if ($paths !== []) {
            $response = $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
        } else {
            $response = $telegram->sendMessage($chatId, $caption);
        }

        $this->messageLifecycle->rememberReviewResponse($draft, $chatId, $response, $paths !== [], $caption);

        $this->sendControls($telegram, $chatId, $draft);
    }

    public function sendControls(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        $response = $telegram->sendMessage(
            $chatId,
            "📋 Итого: черновик #{$draft->id}\nВыберите действие:",
            $this->controlMarkup($draft),
        );

        $this->messageLifecycle->rememberControlResponse($draft, $chatId, $response);

        return $response;
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

        $response = $telegram->sendMessage(
            $chatId,
            "🖼 Какое фото черновика #{$draft->id} {$verb}?\nНомер соответствует позиции в альбоме.",
            ['inline_keyboard' => $buttons],
        );

        $this->messageLifecycle->rememberControlResponse($draft, $chatId, $response);

        return $response;
    }

    private function controlMarkup(ProductDraft $draft): array
    {
        $rows = [
            [
                ['text' => '✅ Добавить', 'callback_data' => "draft:add:{$draft->id}"],
                ['text' => '✖ Отменить', 'callback_data' => "draft:reject:{$draft->id}"],
            ],
            [
                ['text' => '✨ Улучшить', 'callback_data' => "draft:enhance:{$draft->id}"],
                ['text' => '🔄 Заменить', 'callback_data' => "draft:replace:{$draft->id}"],
            ],
        ];

        if ($draft->media()->count() <= 2) {
            $rows[] = [
                ['text' => '🔍 Найти ещё доп. фото', 'callback_data' => "draft:findmore:{$draft->id}"],
            ];
        }

        $rows[] = [
            ['text' => '🔁 Фото не подошли? Найти заново', 'callback_data' => "draft:restage:{$draft->id}"],
        ];
        $rows[] = [
            ['text' => '🧠 Улучшить рецепт источника', 'callback_data' => "draft:retrain:{$draft->id}"],
        ];
        $rows[] = [
            ['text' => '🗑 Удалить фото', 'callback_data' => "draft:delete:{$draft->id}"],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function caption(ProductDraft $draft, string $usageFootnote): string
    {
        $specifications = collect($draft->specifications)->take(6)
            ->map(fn (array $item): string => sprintf(
                '%s %s: %s',
                $this->specificationEmoji((string) ($item['key'] ?? $item['name'] ?? '')),
                $item['name'],
                $item['value'],
            ))
            ->implode("\n");
        $galleryCount = $draft->media()->count();
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $partialNotice = $draft->gallery_status === 'partial'
            ? 'Частичный успех: полный цикл завершён, сохранены лучшие доступные проверенные фото.'
            : null;
        $usageFootnote = ($partialNotice ? PHP_EOL.$partialNotice : '').$usageFootnote;
        $footer = implode("\n", array_filter([
            "📷 Фото: {$galleryCount}",
            $primarySourceUrl !== '' ? "🔗 Источник: {$primarySourceUrl}" : null,
        ]));
        $header = implode("\n", array_filter([
            "🆕 Черновик #{$draft->id} готов к добавлению",
            "🏷 {$draft->title}",
            implode(' · ', array_filter([$draft->brand, $draft->model, $draft->color])),
        ]));
        $fixed = $header."\n\n".$footer.$usageFootnote;
        $available = max(0, 1024 - mb_strlen($fixed) - 8);
        $body = implode("\n\n", array_filter([
            mb_substr((string) $draft->description, 0, (int) floor($available * 0.55)),
            $specifications !== '' ? "⚙️ Характеристики:\n".$specifications : null,
        ]));

        return mb_substr($header."\n\n".mb_substr($body, 0, $available)."\n\n".$footer.$usageFootnote, 0, 1024);
    }

    /**
     * Pick a meaningful emoji for a specification line by matching keywords
     * in its stable snake_case key (falls back to the display name for
     * legacy drafts saved before the key was always populated).
     */
    private function specificationEmoji(string $keyOrName): string
    {
        $needle = Str::lower($keyOrName);

        return match (true) {
            Str::contains($needle, ['cpu', 'processor', 'процессор']) => '🧠',
            Str::contains($needle, ['gpu', 'graphic', 'видеокарт', 'видео_карт']) => '🎮',
            Str::contains($needle, ['ram', 'memory', 'память']) => '💾',
            Str::contains($needle, ['storage', 'ssd', 'hdd', 'disk', 'накопитель', 'диск']) => '💽',
            Str::contains($needle, ['refresh_rate', 'refresh rate', 'частота обновления', 'герц']) => '🔄',
            Str::contains($needle, ['resolution', 'разрешение']) => '📐',
            Str::contains($needle, ['screen_size', 'display', 'screen', 'monitor', 'экран', 'дисплей', 'диагональ']) => '🖥',
            Str::contains($needle, ['battery', 'аккумулятор', 'батаре']) => '🔋',
            Str::contains($needle, ['camera', 'камер']) => '📷',
            Str::contains($needle, ['weight', 'вес']) => '⚖️',
            Str::contains($needle, ['size', 'dimension', 'height', 'width', 'length', 'габарит', 'размер']) => '📏',
            Str::contains($needle, ['color', 'colour', 'цвет']) => '🎨',
            Str::contains($needle, ['port', 'connector', 'interface', 'usb', 'hdmi', 'разъем', 'разъём', 'порт']) => '🔌',
            Str::contains($needle, ['wifi', 'bluetooth', 'network', 'lan', 'сеть']) => '📶',
            Str::contains($needle, ['warranty', 'гарант']) => '🛡',
            Str::contains($needle, ['material', 'материал']) => '🧱',
            Str::contains($needle, ['wheel', 'tire', 'tyre', 'шин', 'колес', 'колёс']) => '🛞',
            Str::contains($needle, ['power', 'watt', 'ватт', 'мощност']) => '⚡',
            Str::contains($needle, ['keyboard', 'клавиатур']) => '⌨️',
            Str::contains($needle, ['audio', 'speaker', 'sound', 'звук', 'колонк']) => '🔊',
            Str::contains($needle, ['os', 'система']) => '🗂',
            default => '▪️',
        };
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
