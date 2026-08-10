<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use App\Services\Products\ProductSourcePriority;
use Illuminate\Support\Facades\Storage;

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

        $this->messageLifecycle->rememberReviewResponse($telegram, $draft, $chatId, $response, $paths !== [], $caption);

        $this->sendControls($telegram, $chatId, $draft);
    }

    public function finalizeRejection(TelegramClient $telegram, ProductDraft $draft): bool
    {
        return $this->messageLifecycle->finalizeRejectedReview($telegram, $draft);
    }

    /**
     * A control message (photo-selection menu, ...) is used up the moment its
     * keyboard is acted on - stripping the keyboard and leaving the message
     * behind (the old pattern) just piles up dead prompts in the chat.
     */
    public function clearControls(TelegramClient $telegram, ProductDraft $draft, string $chatId): void
    {
        $this->messageLifecycle->clearControlMessages($telegram, $draft, $chatId);
    }

    public function sendControls(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "📋 Итого: черновик #{$draft->id}\nВыберите действие:",
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

        if ($action === 'replace') {
            $buttons[] = [[
                'text' => '🔁 Все фото',
                'callback_data' => "draft:restage:{$draft->id}",
            ]];
        }
        $buttons[] = [[
            'text' => '← Назад',
            'callback_data' => "draft:review:{$draft->id}",
        ]];

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "🖼 Какое фото черновика #{$draft->id} {$verb}?\nНомер соответствует позиции в альбоме.",
            ['inline_keyboard' => $buttons],
        );
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
            ['text' => '🔗 Источник', 'callback_data' => "draft:source:{$draft->id}"],
        ];
        $rows[] = [
            ['text' => '🗑 Удалить фото', 'callback_data' => "draft:delete:{$draft->id}"],
        ];

        return ['inline_keyboard' => $rows];
    }

    public function sendSourceMenu(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        $primarySourceUrl = trim((string) $draft->primary_source_url);
        $host = $primarySourceUrl !== '' ? ProductSourcePriority::host($primarySourceUrl) : '';
        $buttons = [
            [['text' => '🧠 Переобучить рецепт', 'callback_data' => "draft:source-retrain:{$draft->id}"]],
            [['text' => '💬 Переобучить с подсказкой', 'callback_data' => "draft:source-hint:{$draft->id}"]],
            [['text' => '🚫 Не использовать источник', 'callback_data' => "draft:source-block:{$draft->id}"]],
            [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
        ];
        $text = $host !== ''
            ? "🔗 Источник черновика #{$draft->id}: {$host}\nЧто сделать?"
            : "🔗 Источник черновика #{$draft->id}\nЧто сделать?";

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            $text,
            ['inline_keyboard' => $buttons],
        );
    }

    public function sendSourceBlockConfirm(TelegramClient $telegram, string $chatId, ProductDraft $draft, string $host): array
    {
        $buttons = [
            [
                ['text' => 'Да, забанить', 'callback_data' => "draft:source-block-confirm:{$draft->id}"],
                ['text' => 'Отмена', 'callback_data' => "draft:source-block-cancel:{$draft->id}"],
            ],
        ];

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "⚠️ Забанить источник {$host}?\nОн перестанет использоваться для ВСЕХ будущих товаров, не только этого черновика.",
            ['inline_keyboard' => $buttons],
        );
    }

    private function caption(ProductDraft $draft, string $usageFootnote): string
    {
        $specifications = collect($draft->specifications)->take(6)
            ->map(fn (array $item): string => sprintf(
                '%s %s: %s',
                SpecificationEmoji::for((string) ($item['key'] ?? $item['name'] ?? '')),
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
