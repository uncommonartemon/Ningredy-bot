<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->post('sendMessage', $payload);
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, ?array $replyMarkup = null): array
    {
        $payload = ['chat_id' => $chatId, 'photo' => $photoUrl];
        if ($caption !== null) {
            $payload['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->post('sendPhoto', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text): array
    {
        return $this->post('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => mb_substr($text, 0, 200),
        ]);
    }

    public function sendPhotoFile(string $chatId, string $path, ?string $caption = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Local Telegram photo is not readable.');
        }

        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            return $this->multipartRequest()->attach('photo', fopen($path, 'rb'), basename($path))
                ->post('sendPhoto', [
                    'chat_id' => $chatId,
                    'caption' => mb_substr((string) $caption, 0, 1024),
                ])->throw()->json();
        } catch (Throwable $exception) {
            throw new RuntimeException(str_replace($token, '[redacted]', $exception->getMessage()), (int) $exception->getCode());
        }
    }

    /** @param array<int, string> $paths */
    public function sendMediaGroupFiles(string $chatId, array $paths, ?string $caption = null): array
    {
        $paths = array_values(array_filter($paths, fn (string $path): bool => is_file($path) && is_readable($path)));

        if ($paths === []) {
            throw new RuntimeException('Local Telegram media files are not readable.');
        }

        if (count($paths) === 1) {
            return [$this->sendPhotoFile($chatId, $paths[0], $caption)];
        }

        $paths = array_slice($paths, 0, 10);
        $request = $this->multipartRequest();
        $media = [];
        $handles = [];

        try {
            foreach ($paths as $index => $path) {
                $name = "photo{$index}";
                $handle = fopen($path, 'rb');

                if ($handle === false) {
                    throw new RuntimeException("Could not open Telegram media file: {$path}");
                }

                $handles[] = $handle;
                $request = $request->attach($name, $handle, basename($path));
                $item = ['type' => 'photo', 'media' => "attach://{$name}"];

                if ($index === 0 && $caption !== null) {
                    $item['caption'] = mb_substr($caption, 0, 1024);
                }

                $media[] = $item;
            }

            return $request->post('sendMediaGroup', [
                'chat_id' => $chatId,
                'media' => json_encode($media, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ])->throw()->json();
        } catch (Throwable $exception) {
            $token = (string) config('services.telegram.bot_token');
            throw new RuntimeException(str_replace($token, '[redacted]', $exception->getMessage()), (int) $exception->getCode());
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }

    public function getFile(string $fileId): array
    {
        return $this->post('getFile', ['file_id' => $fileId]);
    }

    public function downloadFile(string $filePath): string
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            return Http::timeout(30)->connectTimeout(5)->retry(2, 1200)
                ->get("https://api.telegram.org/file/bot{$token}/{$filePath}")->throw()->body();
        } catch (Throwable $exception) {
            throw new RuntimeException(str_replace($token, '[redacted]', $exception->getMessage()), (int) $exception->getCode());
        }
    }

    public function removeInlineKeyboard(string $chatId, int $messageId): array
    {
        return $this->post('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }

    public function setCommands(): array
    {
        return $this->post('setMyCommands', [
            'commands' => [
                ['command' => 'start', 'description' => 'Открыть панель'],
                ['command' => 'find', 'description' => 'Найти товар'],
                ['command' => 'drafts', 'description' => 'Показать черновики'],
                ['command' => 'status', 'description' => 'Статус сервера'],
                ['command' => 'url', 'description' => 'Текущий адрес сайта'],
                ['command' => 'errors', 'description' => 'Последние ошибки'],
                ['command' => 'new', 'description' => 'Очистить контекст'],
                ['command' => 'help', 'description' => 'Помощь'],
            ],
        ]);
    }

    public function setWebhook(string $url, bool $dropPendingUpdates = false): array
    {
        $configuredSecret = (string) config('services.telegram.webhook_secret');
        if ($configuredSecret === '') {
            throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET is not configured.');
        }
        $secret = preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $configuredSecret) === 1
            ? $configuredSecret
            : hash('sha256', $configuredSecret);

        return $this->post('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    private function post(string $method, array $payload): array
    {
        try {
            return $this->request()->post($method, $payload)->throw()->json();
        } catch (Throwable $exception) {
            $token = (string) config('services.telegram.bot_token');
            $message = $token === '' ? $exception->getMessage() : str_replace($token, '[redacted]', $exception->getMessage());
            throw new RuntimeException($message, (int) $exception->getCode());
        }
    }

    private function request(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }
        $request = Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->asJson()->acceptJson()->timeout(15)->connectTimeout(5)->retry(2, 1200);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $request->withOptions(['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]]);
        }

        return $request;
    }

    private function multipartRequest(): PendingRequest
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $request = Http::baseUrl('https://api.telegram.org/bot'.$token)
            ->acceptJson()->timeout(30)->connectTimeout(5)->retry(2, 1200);

        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $request->withOptions(['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]]);
        }

        return $request;
    }
}
