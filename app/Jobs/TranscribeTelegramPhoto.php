<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramProductUploadIntake;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class TranscribeTelegramPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [15, 60];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('voice');
    }

    public function handle(TelegramClient $telegram, AiErrorPresenter $errors): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);
        if (! $update || $update->processed_at || $update->status === 'cancelled') {
            return;
        }
        // Supplied product material creates a manual draft; a bare label photo
        // keeps the existing OCR-to-search behaviour.
        if (data_get($update->payload, '_manual_product_upload')
            || data_get($update->payload, 'message.media_group_id')
            || trim((string) data_get($update->payload, 'message.caption', '')) !== '') {
            app(TelegramProductUploadIntake::class)->queue($update);

            return;
        }
        $sizes = data_get($update->payload, 'message.photo', []);
        $largest = is_array($sizes) ? end($sizes) : false;
        $fileId = is_array($largest) ? (string) data_get($largest, 'file_id') : '';
        $size = is_array($largest) ? (int) data_get($largest, 'file_size', 0) : 0;
        $caption = trim((string) data_get($update->payload, 'message.caption', ''));
        $maxBytes = (int) config('services.telegram_photo.max_bytes', 10485760);

        if ($fileId === '' || $size > $maxBytes) {
            $message = $fileId === ''
                ? 'Telegram не передал файл фотографии.'
                : 'Фото слишком большое. Лимит: '.round($maxBytes / 1048576).' МБ.';
            $telegram->sendMessage($update->chat_id, $message);
            $update->update(['status' => 'rejected', 'error' => $message, 'processed_at' => now()]);

            return;
        }

        try {
            $file = $telegram->getFile($fileId);
            if ($update->refresh()->processed_at || $update->status === 'cancelled') {
                return;
            }
            $filePath = (string) data_get($file, 'result.file_path');
            throw_if($filePath === '', RuntimeException::class, 'Telegram did not return photo file_path.');
            $bytes = $telegram->downloadFile($filePath);
            if ($update->refresh()->processed_at || $update->status === 'cancelled') {
                return;
            }

            $data = app(VisionService::class)->readText(
                $bytes,
                new VisionCallContext($update->id,
                    timeoutSeconds: (int) config('services.product_image_vision.timeout', 45),
                    useSearchBudget: false),
            );
            $text = trim((string) ($data['text'] ?? ''));
            throw_if($text === '', RuntimeException::class, 'Photo text extraction is empty.');

            if ($caption !== '') {
                $text = "{$text}\n{$caption}";
            }

            $saved = TelegramUpdate::query()->whereKey($update->id)->whereNull('processed_at')
                ->where('status', '!=', 'cancelled')
                ->update(['text' => $text, 'status' => 'transcribed', 'error' => null]);
            if (! $saved) {
                return;
            }
            $label = ($data['has_text'] ?? false) ? '📷 Распознан текст на фото' : '📷 Описание фото';
            $telegram->sendMessage($update->chat_id, "{$label}:\n{$text}");
            ProcessTelegramMessage::dispatch($update->id)->afterCommit();
        } catch (Throwable $exception) {
            if ($update->refresh()->processed_at || $update->status === 'cancelled') {
                return;
            }
            $presented = $errors->present($exception);
            $update->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            if ($presented['retryable']) {
                if ($this->attempts() < $this->tries) {
                    $telegram->sendMessage($update->chat_id, 'Не удалось распознать фото, повторяю попытку.');
                }

                throw $exception;
            }

            $telegram->sendMessage($update->chat_id, 'Не удалось распознать фото. '.$presented['message']);
            $update->update(['processed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $update = TelegramUpdate::query()->find($this->telegramUpdateId);

        if (! $update?->chat_id || $update->processed_at) {
            return;
        }

        try {
            $message = app(AiErrorPresenter::class)->present($exception)['message'];
            app(TelegramClient::class)->sendMessage($update->chat_id, 'Не удалось распознать фото после повторной попытки. '.$message);
            $update->update(['processed_at' => now()]);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }
}
