<?php

namespace App\Services\Telegram;

use App\Models\AiOperation;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use Throwable;

/** Durable receipts for webhook replies, separate from the business action. */
final class TelegramWebhookDelivery
{
    private static ?int $updateId = null;

    private static bool $checkCancellation = false;

    public static function begin(int $updateId): void
    {
        self::$updateId = $updateId;
        self::$checkCancellation = false;
    }

    public static function end(): void
    {
        self::$updateId = null;
        self::$checkCancellation = false;
    }

    /** A queue reply can run inside a synchronous webhook without losing its outer receipt scope. */
    public static function within(int $updateId, callable $callback, bool $checkCancellation = false): mixed
    {
        $previous = self::$updateId;
        $previousCheckCancellation = self::$checkCancellation;
        self::$updateId = $updateId;
        self::$checkCancellation = $checkCancellation;

        try {
            return $callback();
        } finally {
            self::$updateId = $previous;
            self::$checkCancellation = $previousCheckCancellation;
        }
    }

    public static function send(string $method, array $payload, callable $deliver): array
    {
        if (self::$checkCancellation && self::$updateId !== null) {
            app(ProductSearchCancellation::class)->throwIfCancelled(self::$updateId);
        }
        // Edits/deletes and short-lived callback toasts have their own fallback;
        // a failed optional toast must not hold up an already completed action.
        if (self::$updateId === null || ! in_array($method, ['sendMessage', 'sendPhoto', 'sendPhotoFile', 'sendMediaGroupFiles'], true)) {
            return $deliver();
        }
        $update = TelegramUpdate::find(self::$updateId);
        $key = 'telegram-delivery:'.self::$updateId.':'.hash('sha256', json_encode([$method, $payload], JSON_THROW_ON_ERROR));
        $receipt = AiOperation::firstOrCreate(['idempotency_key' => $key], [
            'telegram_update_id' => self::$updateId, 'telegram_user_id' => $update?->telegram_user_id,
            'tool' => self::class, 'action' => 'telegram_webhook_delivery', 'status' => 'running',
            'payload' => ['method' => $method, 'body' => $payload],
        ]);
        if ($receipt->status === 'completed') {
            return $receipt->result;
        }
        try {
            $response = $deliver();
            $receipt->update(['status' => 'completed', 'result' => $response, 'executed_at' => now(), 'error' => null]);

            return $response;
        } catch (Throwable $error) {
            $token = (string) config('services.telegram.bot_token');
            $message = $token !== '' ? str_replace($token, '[redacted]', $error->getMessage()) : $error->getMessage();
            $receipt->update(['status' => 'failed', 'error' => mb_substr($message, 0, 1000)]);
            throw $error;
        }
    }
}
