<?php

namespace App\Jobs;

use App\Ai\Agents\ServerAssistantAgent;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\Product;
use App\Models\ProductDraft;
use App\Models\TelegramChatState;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUsageReporter;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProcessTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 480;

    public array $backoff = [10, 60, 300];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('assistant');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('telegram-update:'.$this->telegramUpdateId))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(TelegramClient $telegram, AiErrorPresenter $errors): void
    {
        $update = TelegramUpdate::query()->findOrFail($this->telegramUpdateId);

        if ($update->processed_at) {
            return;
        }

        $aiSettings = app(AiSettings::class);
        $provider = $aiSettings->providerFor('server_assistant');
        $model = $aiSettings->modelFor('server_assistant');
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => $provider,
            'model' => $model,
            'status' => 'running',
            'prompt' => $update->text,
            'started_at' => now(),
        ]);
        $update->update(['status' => 'processing', 'error' => null]);

        try {
            $agent = ServerAssistantAgent::make($update);
            $state = TelegramChatState::query()->firstOrCreate(
                ['chat_id' => $update->chat_id],
                ['telegram_user_id' => $update->telegram_user_id],
            );
            $user = User::query()->first();

            if ($user && $state->conversation_id) {
                $agent->continue($state->conversation_id, as: $user);
            } elseif ($user) {
                $agent->forUser($user);
            }

            $prompt = $update->reply_to_text
                ? "[Пользователь ответил (Reply) на сообщение бота: \"{$update->reply_to_text}\"]\n{$update->text}"
                : (string) $update->text;
            $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: 300);
            $normalizedResponse = $response->toArray();

            if (is_string($normalizedResponse['message'] ?? null)) {
                $normalizedResponse['message'] = mb_substr($normalizedResponse['message'], 0, 12000);
            }

            foreach (['product_ids', 'operation_ids'] as $idField) {
                $normalizedResponse[$idField] = collect($normalizedResponse[$idField] ?? [])
                    ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
                    ->map(fn (int|string $id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->take(100)
                    ->values()
                    ->all();
            }

            $data = Validator::make($normalizedResponse, [
                'response_type' => ['required', 'string'],
                'message' => ['required', 'string', 'max:12000'],
                'draft_id' => ['nullable', 'integer'],
                'product_ids' => ['present', 'array', 'max:100'],
                'product_ids.*' => ['integer', 'distinct'],
                'operation_ids' => ['present', 'array', 'max:100'],
                'operation_ids.*' => ['integer', 'distinct'],
            ])->validate();

            if ($response->conversationId) {
                $state->update([
                    'telegram_user_id' => $update->telegram_user_id,
                    'conversation_id' => $response->conversationId,
                ]);
            }

            $run->update([
                'invocation_id' => $response->invocationId,
                'status' => 'completed',
                'response' => $data,
                'usage' => $response->usage->toArray(),
                'completed_at' => now(),
            ]);
            $update->update(['status' => 'completed', 'processed_at' => now()]);

            $draft = empty($data['draft_id']) ? null : ProductDraft::query()->find($data['draft_id']);
            $deletion = AiOperation::query()
                ->where('telegram_update_id', $update->id)
                ->where('action', 'delete_product')
                ->where('status', 'awaiting_confirmation')
                ->latest('id')
                ->first();

            $usageFootnote = $this->usageFootnote($update->id);

            if ($draft) {
                $this->sendDraftApproval($telegram, $update->chat_id, $draft, $usageFootnote);
            } elseif ($deletion) {
                $productTitle = (string) data_get($deletion->payload, 'title', 'Товар');
                $productId = (int) $deletion->target_id;
                $telegram->sendMessage(
                    $update->chat_id,
                    "Подтвердите безвозвратное удаление:\n\n#{$productId} · {$productTitle}\n\nБудут удалены товар, варианты, характеристики и локальные фотографии.".$usageFootnote,
                    [
                        'inline_keyboard' => [[
                            [
                                'text' => 'Удалить навсегда',
                                'callback_data' => "product:delete:confirm:{$deletion->id}",
                            ],
                            [
                                'text' => 'Отмена',
                                'callback_data' => "product:delete:cancel:{$deletion->id}",
                            ],
                        ]],
                    ],
                );
            } elseif ($data['response_type'] === 'catalog_results' && ! empty($data['product_ids'])) {
                $telegram->sendMessage($update->chat_id, $data['message'].$usageFootnote);
                $this->sendProductCards($telegram, $update->chat_id, $data['product_ids']);
            } else {
                $telegram->sendMessage($update->chat_id, $data['message'].$usageFootnote);
            }
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);
            $update->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 5000)]);
            $presented = $errors->present($exception, $run->id);

            // Notify on every attempt, not just the final one - the user
            // should never be left wondering why nothing is happening while
            // a retryable error (rate limit, timeout, network) is silently
            // retried in the background.
            try {
                $telegram->sendMessage($update->chat_id, $presented['message']);
            } catch (Throwable $notifyException) {
                report($notifyException);
            }

            if ($presented['retryable']) {
                throw $exception;
            }

            $update->update(['processed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if (! $update?->chat_id || $update->processed_at) {
            return;
        }

        $run = $update->aiRuns()->latest('id')->first();

        // handle()'s catch block already notifies on every attempt, including
        // the final one that lands here when retries are exhausted - skip a
        // duplicate send when this exact failure was already reported.
        if ($exception && $run && $run->error === mb_substr($exception->getMessage(), 0, 5000)) {
            $update->update(['processed_at' => now()]);

            return;
        }

        try {
            $message = app(AiErrorPresenter::class)->present($exception ?: $run?->error, $run?->id)['message'];
            app(TelegramClient::class)->sendMessage($update->chat_id, $message);
            $update->update(['processed_at' => now()]);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }

    private function usageFootnote(int $telegramUpdateId): string
    {
        $usage = app(AiUsageReporter::class)->forTelegramUpdate($telegramUpdateId);
        $tokens = (int) ($usage['tokens']['total'] ?? 0);

        if ($tokens <= 0) {
            return '';
        }

        $line = "\n\n🔢 Токены: ".number_format($tokens, 0, '.', ' ');

        if ($usage['estimated_cost_usd'] !== null) {
            $line .= sprintf(' (~$%s)', number_format((float) $usage['estimated_cost_usd'], 4));
        }

        return $line;
    }

    /** @param array<int, int> $productIds */
    private function sendProductCards(TelegramClient $telegram, string $chatId, array $productIds): void
    {
        $productIds = array_slice($productIds, 0, 10);
        $products = Product::query()
            ->with(['brand:id,name', 'defaultVariant'])
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(fn (Product $product): int => (int) array_search($product->id, $productIds, true));

        foreach ($products as $product) {
            try {
                $caption = $this->productCardCaption($product);
                $paths = $product->media()
                    ->where('type', 'image')
                    ->whereIn('verification_status', ['verified', 'source_verified', 'manual'])
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->get()
                    ->filter(fn ($item): bool => filled($item->disk) && filled($item->path))
                    ->map(fn ($item): string => Storage::disk($item->disk)->path($item->path))
                    ->filter(fn (string $path): bool => is_file($path) && is_readable($path))
                    ->take(10)
                    ->values()
                    ->all();

                if ($paths !== []) {
                    $telegram->sendMediaGroupFiles($chatId, $paths, $caption);
                } else {
                    $telegram->sendMessage($chatId, $caption);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function productCardCaption(Product $product): string
    {
        $product->loadMissing([
            'brand', 'defaultVariant.attributes.definition', 'attributes.definition', 'sources',
        ]);
        $variant = $product->defaultVariant;
        $identity = collect([$product->brand?->name, $product->model])->filter()->unique()->implode(' · ');
        $price = $variant?->price !== null
            ? trim(number_format((float) $variant->price, 0, '.', ' ').' '.(string) ($variant->currency ?? ''))
            : null;
        $stock = match ($variant?->stock_status) {
            'in_stock' => 'В наличии',
            'out_of_stock' => 'Нет в наличии',
            'preorder' => 'Предзаказ',
            default => null,
        };
        $publicUrl = rtrim((string) AppSetting::valueFor(
            AppSetting::TELEGRAM_PROXY_URL,
            (string) config('services.telegram.proxy_url'),
        ), '/');
        $link = $publicUrl !== '' ? "{$publicUrl}/products/{$product->slug}" : null;

        $header = implode("\n", array_filter([
            "#{$product->id} · {$product->title}",
            $identity !== '' && $identity !== $product->title ? $identity : null,
            implode(' · ', array_filter([$price, $stock])) ?: null,
        ]));

        $specs = collect($product->attributes)
            ->merge($variant?->attributes ?? [])
            ->filter(fn ($attribute): bool => $attribute->definition !== null && filled($attribute->value))
            ->map(fn ($attribute): string => "• {$attribute->definition->label}: {$attribute->value}".($attribute->unit ? " {$attribute->unit}" : ''))
            ->implode("\n");
        $sources = $product->sources->pluck('url')->filter()->take(3)
            ->map(fn (string $url): string => "• {$url}")->implode("\n");
        // Telegram caps photo/album captions at 1024 chars (vs 4096 for plain
        // text messages), so specs/sources are clipped to whatever fits after
        // the header and link - same budget technique as draftSummary().
        $body = implode("\n\n", array_filter([
            $specs !== '' ? "Характеристики:\n{$specs}" : null,
            $sources !== '' ? "Источники:\n{$sources}" : null,
        ]));
        $available = max(0, 1024 - mb_strlen($header."\n\n".($link ?? '')) - 8);

        return mb_substr(implode("\n\n", array_filter([
            $header,
            mb_substr($body, 0, $available),
            $link,
        ])), 0, 1024);
    }

    private function draftSummary(ProductDraft $draft): string
    {
        $specifications = collect($draft->specifications)->take(12)
            ->map(fn (array $item): string => "• {$item['name']}: {$item['value']}")->implode("\n");
        $sources = collect($draft->sources)->take(5)
            ->map(fn (array $source): string => "• {$source['url']}")->implode("\n");

        return mb_substr(implode("\n\n", array_filter([
            "Найден товар — черновик #{$draft->id}",
            $draft->title,
            implode(' · ', array_filter([$draft->brand, $draft->model, $draft->color])),
            $draft->description,
            $specifications ? "Характеристики:\n{$specifications}" : null,
            $sources ? "Источники:\n{$sources}" : null,
            'Товар ещё не опубликован. Нажмите кнопку ниже, чтобы добавить его в каталог.',
        ])), 0, 4096);
    }

    private function sendDraftApproval(
        TelegramClient $telegram,
        string $chatId,
        ProductDraft $draft,
        string $usageFootnote,
    ): void {
        app(DraftTelegramPresenter::class)->sendReview($telegram, $chatId, $draft, $usageFootnote);
    }
}
