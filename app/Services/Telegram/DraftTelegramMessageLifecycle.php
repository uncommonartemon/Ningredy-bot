<?php

namespace App\Services\Telegram;

use App\Models\ProductDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DraftTelegramMessageLifecycle
{
    public function rememberReviewResponse(
        TelegramClient $telegram,
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

        // A freshly posted album (restage/replace/retrain/... all repost it)
        // makes any control message left over from the previous review cycle
        // orphaned - reusing it would edit a keyboard onto that old, no
        // longer relevant post instead of showing it under the new one.
        $this->clearControlMessages($telegram, $draft, $chatId);

        $draft->forceFill([
            'telegram_review_chat_id' => $chatId,
            'telegram_review_message_ids' => $messageIds,
            'telegram_review_has_media' => $hasMedia,
            'telegram_review_caption' => $caption,
            'telegram_review_finalized_at' => null,
        ])->save();
    }

    public function clearControlMessages(TelegramClient $telegram, ProductDraft $draft, string $chatId): void
    {
        $draft->refresh();
        $existingIds = collect($draft->telegram_control_message_ids ?? [])
            ->map(fn ($messageId): int => (int) $messageId)
            ->filter(fn (int $messageId): bool => $messageId > 0)
            ->values();

        $this->deleteStrayControlMessages($telegram, $chatId, $existingIds);
        $draft->forceFill(['telegram_control_message_ids' => []])->save();
    }

    public function finalizedReviewCaption(ProductDraft $draft, string $firstLine): ?string
    {
        $caption = $draft->telegram_review_caption;

        if (! is_string($caption) || trim($caption) === '') {
            return null;
        }

        return preg_replace('/\A[^\r\n]*/u', $firstLine, $caption, 1);
    }

    /**
     * Rejecting a draft used to strip the control panel's keyboard and post a
     * brand new "отклонён" message, leaving the original review post (photo
     * album + caption) sitting there unchanged forever. This instead edits
     * that same review post's first line in place - mirroring what the
     * approve path already does via finalizedReviewCaption()/StoreProductImages
     * - and deletes the now-stale control messages. Returns false when there
     * is nothing to edit (old draft predating this tracking, or missing
     * caption), so the caller can fall back to a fresh message.
     */
    public function finalizeRejectedReview(TelegramClient $telegram, ProductDraft $draft): bool
    {
        $reviewTarget = $this->reviewTarget($draft);

        if ($reviewTarget === null) {
            return false;
        }

        $caption = $this->finalizedReviewCaption($draft, "✖ Черновик #{$draft->id} отклонён.");

        if ($caption === null) {
            return false;
        }

        try {
            if ($reviewTarget['has_media']) {
                $telegram->editMessageCaption($reviewTarget['chat_id'], $reviewTarget['message_id'], $caption);
            } else {
                $telegram->editMessageText($reviewTarget['chat_id'], $reviewTarget['message_id'], $caption);
            }
        } catch (Throwable $exception) {
            // The review post may no longer exist or be editable (aged out,
            // deleted by the operator, ...) - the caller falls back to a
            // fresh "отклонён" message instead.
            report($exception);

            return false;
        }

        $this->finalizeEditedReview($telegram, $draft);

        return true;
    }

    /**
     * A draft has at most one active "control" message at a time (main panel,
     * photo-selection menu, ...) - every menu transition used to just send a
     * fresh message and leave the previous one sitting in the chat with its
     * keyboard stripped, so the same handful of screens kept piling up as the
     * operator navigated back and forth. This reuses that single message in
     * place (edits its text/keyboard) whenever possible, and only falls back
     * to deleting it and sending a new one when there is nothing to edit yet
     * or the edit itself fails (message aged out, deleted by the operator, ...).
     *
     * @param  array<int, array<int, array<string, string>>>  $replyMarkup
     * @return array<string, mixed>
     */
    public function replaceControlMessage(
        TelegramClient $telegram,
        ProductDraft $draft,
        string $chatId,
        string $text,
        array $replyMarkup,
    ): array {
        $draft->refresh();
        $existingIds = collect($draft->telegram_control_message_ids ?? [])
            ->map(fn ($messageId): int => (int) $messageId)
            ->filter(fn (int $messageId): bool => $messageId > 0)
            ->values();
        $primaryId = $existingIds->first();

        if ($primaryId !== null) {
            try {
                $response = $telegram->editMessageText($chatId, $primaryId, $text, replyMarkup: $replyMarkup);
                $this->deleteStrayControlMessages($telegram, $chatId, $existingIds->slice(1));
                $this->rememberControlMessageIds($draft, $chatId, [$primaryId]);

                return $response;
            } catch (Throwable $exception) {
                // The message may no longer exist or be editable (aged out,
                // deleted by the operator, ...) - fall through to a fresh send.
                report($exception);
            }
        }

        $this->deleteStrayControlMessages($telegram, $chatId, $existingIds);
        $response = $telegram->sendMessage($chatId, $text, $replyMarkup);
        $this->rememberControlMessageIds($draft, $chatId, $this->extractMessageIds($response));

        return $response;
    }

    private function deleteStrayControlMessages(TelegramClient $telegram, string $chatId, Collection $messageIds): void
    {
        if ($messageIds->isEmpty()) {
            return;
        }

        try {
            $telegram->deleteMessages($chatId, $messageIds->all());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<int, int> $messageIds */
    private function rememberControlMessageIds(ProductDraft $draft, string $chatId, array $messageIds): void
    {
        DB::transaction(function () use ($draft, $chatId, $messageIds): void {
            $storedDraft = ProductDraft::query()->lockForUpdate()->find($draft->getKey());

            if (! $storedDraft) {
                return;
            }

            $storedDraft->forceFill([
                'telegram_review_chat_id' => $chatId,
                'telegram_control_message_ids' => array_values(array_unique(array_filter(
                    array_map('intval', $messageIds),
                    fn (int $messageId): bool => $messageId > 0,
                ))),
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
