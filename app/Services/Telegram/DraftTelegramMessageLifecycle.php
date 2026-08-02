<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use Illuminate\Support\Facades\DB;
use Throwable;

class DraftTelegramMessageLifecycle
{
    public function rememberReviewResponse(
        ProductDraft $draft,
        string $chatId,
        array $response,
        bool $hasMedia,
        ?string $caption = null,
    ): void {
        $messageIds = $this->extractMessageIds($response);

        if ($messageIds === []) {
            return;
        }

        $draft->forceFill([
            'telegram_review_chat_id' => $chatId,
            'telegram_review_message_ids' => $messageIds,
            'telegram_review_has_media' => $hasMedia,
            'telegram_review_caption' => $caption,
            'telegram_review_finalized_at' => null,
        ])->save();
    }

    public function finalizedReviewCaption(ProductDraft $draft, int $productId): ?string
    {
        $caption = $draft->telegram_review_caption;

        if (! is_string($caption) || trim($caption) === '') {
            return null;
        }

        return preg_replace(
            '/\A[^\r\n]*/u',
            "✅ Товар #{$productId} добавлен в каталог",
            $caption,
            1,
        );
    }

    public function rememberControlResponse(ProductDraft $draft, string $chatId, array $response): void
    {
        $messageIds = $this->extractMessageIds($response);

        if ($messageIds === []) {
            return;
        }

        DB::transaction(function () use ($draft, $chatId, $messageIds): void {
            $storedDraft = ProductDraft::query()->lockForUpdate()->find($draft->getKey());

            if (! $storedDraft) {
                return;
            }

            $storedDraft->forceFill([
                'telegram_review_chat_id' => $chatId,
                'telegram_control_message_ids' => collect($storedDraft->telegram_control_message_ids ?? [])
                    ->merge($messageIds)
                    ->map(fn ($messageId): int => (int) $messageId)
                    ->filter(fn (int $messageId): bool => $messageId > 0)
                    ->unique()
                    ->values()
                    ->all(),
            ])->save();
        });

        $draft->refresh();
    }

    public function finalizeEditedReview(TelegramClient $telegram, ProductDraft $draft): void
    {
        $this->deleteTracked($telegram, $draft, includeReview: false);
    }

    public function finalizeFallbackPost(TelegramClient $telegram, ProductDraft $draft): void
    {
        $this->deleteTracked($telegram, $draft, includeReview: true);
    }

    /** @return array{chat_id: string, message_id: int, has_media: bool}|null */
    public function reviewTarget(ProductDraft $draft): ?array
    {
        $draft->refresh();
        $messageId = (int) collect($draft->telegram_review_message_ids ?? [])->first();
        $chatId = trim((string) $draft->telegram_review_chat_id);

        if ($chatId === '' || $messageId <= 0) {
            return null;
        }

        return [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'has_media' => (bool) $draft->telegram_review_has_media,
        ];
    }

    private function deleteTracked(TelegramClient $telegram, ProductDraft $draft, bool $includeReview): void
    {
        $draft->refresh();
        $chatId = trim((string) $draft->telegram_review_chat_id);
        $controlIds = collect($draft->telegram_control_message_ids ?? []);
        $storedReviewIds = collect($draft->telegram_review_message_ids ?? []);
        $reviewIds = $includeReview ? $storedReviewIds : collect();
        $messageIds = $controlIds->merge($reviewIds)
            ->map(fn ($messageId): int => (int) $messageId)
            ->filter(fn (int $messageId): bool => $messageId > 0)
            ->unique()
            ->values();

        if ($chatId === '' || $messageIds->isEmpty()) {
            $draft->forceFill(['telegram_review_finalized_at' => now()])->save();

            return;
        }

        $remaining = collect();

        foreach ($messageIds->chunk(100) as $chunk) {
            try {
                $telegram->deleteMessages($chatId, $chunk->all());
            } catch (Throwable $exception) {
                report($exception);
                $remaining = $remaining->merge($chunk);
            }
        }

        $remainingControls = $controlIds->intersect($remaining)->values()->all();
        $remainingReviews = $includeReview
            ? $reviewIds->intersect($remaining)->values()->all()
            : $storedReviewIds->values()->all();

        $draft->forceFill([
            'telegram_control_message_ids' => $remainingControls,
            'telegram_review_message_ids' => $remainingReviews,
            'telegram_review_finalized_at' => now(),
        ])->save();
    }

    /** @return array<int, int> */
    private function extractMessageIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        if (isset($value['message_id']) && is_numeric($value['message_id'])) {
            $ids[] = (int) $value['message_id'];
        }

        foreach ($value as $child) {
            $ids = array_merge($ids, $this->extractMessageIds($child));
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }
}
