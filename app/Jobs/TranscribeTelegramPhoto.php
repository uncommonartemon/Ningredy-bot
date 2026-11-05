<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductInputContext;
use App\Services\Products\ProductSearchIntentDetector;
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
        $caption = trim((string) data_get($update->payload, 'message.caption', ''));
        $detector = app(ProductSearchIntentDetector::class);
        $routing = app(AiSettings::class)->photoCaptionRouting();
        // Albums, a refusal to search and the operator's own offer remain manual
        // material. A standalone labelled photo uses the same OCR path as a bare
        // label; its caption remains the human request, not extracted evidence.
        if (data_get($update->payload, '_manual_product_upload')
            || data_get($update->payload, 'message.media_group_id')
            || $detector->photoCaptionIsManual($caption, $routing)) {
            app(TelegramProductUploadIntake::class)->queue($update);

            return;
        }
        $sizes = data_get($update->payload, 'message.photo', []);
        $largest = is_array($sizes) ? end($sizes) : false;
        $fileId = is_array($largest) ? (string) data_get($largest, 'file_id') : '';
        $size = is_array($largest) ? (int) data_get($largest, 'file_size', 0) : 0;
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
            // Module 71: with a caption that neither asks for a search nor refuses one,
            // the photo decides - a label with a part number or model is a search (the
            // colleague's #140), a picture with no code and a description is the
            // operator's own product, uploaded as before.
            if ($caption !== '' && ! $detector->hasExplicitSearchRequest($caption)
                && $routing === AiSettings::PHOTO_CAPTION_LABEL && ! self::showsAProductCode($data)) {
                app(TelegramProductUploadIntake::class)->queue($update, $caption);

                return;
            }

            if ($caption !== '') {
                $text = "{$text}\n{$caption}";
            }

            $update->refresh();
            $payload = is_array($update->payload) ? $update->payload : [];
            $payload[ProductInputContext::PAYLOAD_KEY] = ProductInputContext::fromPhotoExtraction($update, $data, [
                'sha256' => hash('sha256', $bytes),
                'file_unique_id' => is_array($largest) ? ($largest['file_unique_id'] ?? null) : null,
            ]);
            $saved = TelegramUpdate::query()->whereKey($update->id)->whereNull('processed_at')
                ->where('status', '!=', 'cancelled')
                ->update(['text' => $text, 'status' => 'transcribed', 'error' => null,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
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

    /**
     * The read photo names a product: an identifier that is not a brand, family,
     * serial or regulatory code, or - from a provider that returns only text - a
     * code-like token of letters and digits.
     *
     * @param  array<string, mixed>  $data
     */
    private static function showsAProductCode(array $data): bool
    {
        if (($data['has_text'] ?? false) !== true) {
            return false;
        }
        foreach (is_array($data['identifiers'] ?? null) ? $data['identifiers'] : [] as $item) {
            $kind = mb_strtolower(trim((string) ($item['kind'] ?? '')));
            $value = trim((string) ($item['value'] ?? ''));
            if ($value !== '' && preg_match('/\d/', $value) === 1
                && ! in_array($kind, ['brand', 'family', 'serial', 'regulatory model', 'regulatory', 'other'], true)) {
                return true;
            }
        }

        return preg_match('/\b(?=[A-Z0-9\/-]*\d)(?=[A-Z0-9\/-]*[A-Z])[A-Z0-9\/-]{6,}\b/i', (string) ($data['text'] ?? '')) === 1;
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
