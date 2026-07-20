<?php

namespace App\Jobs;

use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Transcription;
use RuntimeException;
use Throwable;

class TranscribeTelegramVoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public array $backoff = [15, 60];

    public function __construct(public int $telegramUpdateId)
    {
        $this->onQueue('voice');
    }

    public function handle(TelegramClient $telegram, AiErrorPresenter $errors): void
    {
        $update = TelegramUpdate::query()->findOrFail($this->telegramUpdateId);
        $voice = data_get($update->payload, 'message.voice', []);
        $fileId = (string) data_get($voice, 'file_id');
        $duration = (int) data_get($voice, 'duration', 0);
        $size = (int) data_get($voice, 'file_size', 0);
        $maxSeconds = (int) config('services.voice_transcription.max_seconds', 300);
        $maxBytes = (int) config('services.voice_transcription.max_bytes', 20971520);

        if ($fileId === '' || $duration > $maxSeconds || $size > $maxBytes) {
            $message = $fileId === ''
                ? 'Telegram не передал файл голосового сообщения.'
                : "Голосовое слишком длинное или большое. Лимит: {$maxSeconds} секунд и ".round($maxBytes / 1048576).' МБ.';
            $telegram->sendMessage($update->chat_id, $message);
            $update->update(['status' => 'rejected', 'error' => $message, 'processed_at' => now()]);

            return;
        }

        $path = storage_path("app/private/telegram-voice/{$update->id}.ogg");

        try {
            $file = $telegram->getFile($fileId);
            $filePath = (string) data_get($file, 'result.file_path');
            throw_if($filePath === '', RuntimeException::class, 'Telegram did not return voice file_path.');
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $telegram->downloadFile($filePath));

            $transcription = Transcription::fromPath($path, 'audio/ogg')
                ->timeout(120)
                ->generate(
                    (string) config('services.voice_transcription.provider', 'openai'),
                    (string) config('services.voice_transcription.model', 'gpt-4o-transcribe'),
                );
            $text = trim($transcription->text);
            throw_if($text === '', RuntimeException::class, 'Voice transcription is empty.');

            $update->update(['text' => $text, 'status' => 'transcribed', 'error' => null]);
            $telegram->sendMessage($update->chat_id, "🎙 Распознано:\n{$text}");
            ProcessTelegramMessage::dispatch($update->id)->afterCommit();
        } catch (Throwable $exception) {
            $presented = $errors->present($exception);
            $update->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            if ($presented['retryable']) {
                if ($this->attempts() < $this->tries) {
                    $telegram->sendMessage($update->chat_id, 'Не удалось распознать голосовое, повторяю попытку.');
                }

                throw $exception;
            }

            $telegram->sendMessage($update->chat_id, 'Не удалось распознать голосовое. '.$presented['message']);
            $update->update(['processed_at' => now()]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
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
            app(TelegramClient::class)->sendMessage($update->chat_id, 'Не удалось распознать голосовое после повторной попытки. '.$message);
            $update->update(['processed_at' => now()]);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
    }
}
