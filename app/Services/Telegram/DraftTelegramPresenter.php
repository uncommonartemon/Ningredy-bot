<?php

namespace App\Services\Telegram;

use App\Models\Category;
use App\Models\ProductDraft;
use App\Services\Ai\AiSettings;
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

    public function clearForSearchContinuation(TelegramClient $telegram, ProductDraft $draft): void
    {
        $this->messageLifecycle->clearForRefresh($telegram, $draft);
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
        $mediaCount = $draft->media()->count();
        $searchPaused = in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted'], true);

        if ($searchPaused && $mediaCount === 0) {
            $buttonLabel = $draft->gallery_search_stop_reason === 'exhausted'
                ? '▶️ Продолжить поиск фото'
                : '▶️ Продолжить поиск фото (+$'.number_format(app(AiSettings::class)->maxSearchCostUsd(), 2).')';

            return ['inline_keyboard' => [
                [[
                    'text' => $buttonLabel,
                    'callback_data' => "draft:continue-search:{$draft->id}",
                ]],
                [[
                    'text' => '🔗 Источник',
                    'callback_data' => "draft:source:{$draft->id}",
                ]],
                [[
                    'text' => '✖ Отменить',
                    'callback_data' => "draft:reject:{$draft->id}",
                ]],
            ]];
        }

        // The publishing action gets its own row: it used to sit shoulder to
        // shoulder with the irreversible "Отменить", which is the one mis-tap
        // on this card that costs a whole search.
        $rows = [
            [['text' => '✅ Добавить в каталог', 'callback_data' => "draft:add:{$draft->id}"]],
            [
                ['text' => '🖼 Фото', 'callback_data' => "draft:photos:{$draft->id}"],
                ['text' => '🔗 Источник', 'callback_data' => "draft:source:{$draft->id}"],
            ],
        ];

        if (in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted'], true)) {
            $label = $draft->gallery_search_stop_reason === 'exhausted'
                ? '▶️ Продолжить поиск'
                : '▶️ Продолжить поиск (+$'.number_format(app(AiSettings::class)->maxSearchCostUsd(), 2).')';
            $rows[] = [[
                'text' => $label,
                'callback_data' => "draft:continue-search:{$draft->id}",
            ]];
        } elseif ($mediaCount <= 2) {
            $rows[] = [
                ['text' => '🔍 Найти ещё доп. фото', 'callback_data' => "draft:findmore:{$draft->id}"],
            ];
        }

        // Retraining is buried under "Источник" for a normal card, because a
        // working gallery needs no explanation. When the result itself says the
        // extraction fell short, the operator's hint is the single most useful
        // thing they can give, so it stops being a two-tap discovery problem.
        if ($this->galleryFellShort($draft, $mediaCount)) {
            $rows[] = [[
                'text' => '🧠 Переобучить с подсказкой',
                'callback_data' => "draft:source-hint:{$draft->id}",
            ]];
        }

        $rows[] = [
            ['text' => '✖ Отменить черновик', 'callback_data' => "draft:reject:{$draft->id}"],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * "Fell short" is read from what the search itself recorded, not guessed
     * from the photo count alone: a partial gallery is one the pipeline already
     * knows is incomplete, and a card under its own category minimum is one the
     * operator will have to fix by hand anyway.
     */
    private function galleryFellShort(ProductDraft $draft, int $mediaCount): bool
    {
        if ($mediaCount === 0) {
            return false;
        }

        if ($draft->gallery_status === 'partial') {
            return true;
        }

        $category = trim((string) $draft->category);
        $minimum = $category === ''
            ? 0
            : (int) (Category::query()->where('slug', $category)->first()?->minimumVerifiedImages() ?? 0);

        return $minimum > 0 && $mediaCount < $minimum;
    }

    public function sendPhotoMenu(TelegramClient $telegram, string $chatId, ProductDraft $draft): array
    {
        // One concept, one entry point: these three used to be three separate
        // top-level buttons that all opened the very same photo picker.
        $buttons = [
            [
                ['text' => '✨ Улучшить', 'callback_data' => "draft:enhance:{$draft->id}"],
                ['text' => '🔄 Заменить', 'callback_data' => "draft:replace:{$draft->id}"],
            ],
            [['text' => '🗑 Удалить', 'callback_data' => "draft:delete:{$draft->id}"]],
            [['text' => '← Назад', 'callback_data' => "draft:review:{$draft->id}"]],
        ];

        return $this->messageLifecycle->replaceControlMessage(
            $telegram,
            $draft,
            $chatId,
            "🖼 Фотографии черновика #{$draft->id} ({$draft->media()->count()} шт.)\nЧто сделать?",
            ['inline_keyboard' => $buttons],
        );
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
        $searchPaused = in_array($draft->gallery_search_stop_reason, ['cost_budget', 'time_budget', 'exhausted'], true);
        // images_staged_at stays null only when staging never ran to its own
        // completion at all - e.g. the job was killed by its hard timeout
        // mid-search - as opposed to a normal run that finished and decided
        // there was nothing left to try. Both must block "готов", not just
        // the latter.
        $searchInterrupted = $draft->images_staged_at === null;

        if (($searchPaused || $searchInterrupted) && $galleryCount === 0) {
            $header = "⏸ Черновик #{$draft->id}: поиск фотографий приостановлен\n"
                ."🏷 {$draft->title}\n"
                .implode(' / ', array_filter([$draft->brand, $draft->model, $draft->color]));
            $usageFootnote = "\n".match (true) {
                $searchInterrupted => 'Поиск фотографий прервался технической ошибкой и не успел завершиться штатно. Будет повторён автоматически.',
                $draft->gallery_search_stop_reason === 'exhausted' => 'Все известные и найденные AI-поиском источники проверены без результата. Продолжаю автоматически.',
                default => 'Лимит текущего запуска достигнут. Нажмите «Продолжить поиск фото», чтобы начать новый цикл.',
            }.$usageFootnote;
        }

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
