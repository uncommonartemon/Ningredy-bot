<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function sendMessage(
        string $chatId,
        string $text,
        ?array $replyMarkup = null,
        bool $silent = false,
        ?string $parseMode = null,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => true,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($silent) {
            $payload['disable_notification'] = true;
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        return $this->post('sendMessage', $payload);
    }

    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => mb_substr($text, 0, 4096),
            'disable_web_page_preview' => true,
        ];
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->post('editMessageText', $payload);
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption): array
    {
        return $this->post('editMessageCaption', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => mb_substr($caption, 0, 1024),
        ]);
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

    public function sendPhotoFile(string $chatId, string $path, ?string $caption = null, ?array $replyMarkup = null): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Local Telegram photo is not readable.');
        }

        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $payload = [
            'chat_id' => $chatId,
            'caption' => mb_substr((string) $caption, 0, 1024),
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        [$preparedPath, $temporary] = $this->preparePhotoPath($path);
        $handle = null;

        try {
            $handle = fopen($preparedPath, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Could not open Telegram photo file: {$preparedPath}");
            }

            return $this->multipartRequest()->attach('photo', $handle, basename($preparedPath))
                ->post('sendPhoto', $payload)->throw()->json();
        } catch (Throwable $exception) {
            throw new RuntimeException(str_replace($token, '[redacted]', $exception->getMessage()), (int) $exception->getCode());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($temporary) {
                @unlink($preparedPath);
            }
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
        $temporaryPaths = [];

        try {
            foreach ($paths as $index => $path) {
                [$preparedPath, $temporary] = $this->preparePhotoPath($path);
                $name = "photo{$index}";
                $handle = fopen($preparedPath, 'rb');

                if ($handle === false) {
                    throw new RuntimeException("Could not open Telegram media file: {$preparedPath}");
                }

                if ($temporary) {
                    $temporaryPaths[] = $preparedPath;
                }

                $handles[] = $handle;
                $request = $request->attach($name, $handle, basename($preparedPath));
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

            foreach ($temporaryPaths as $temporaryPath) {
                @unlink($temporaryPath);
            }
        }
    }

    /** @return array{0: string, 1: bool} */
    private function preparePhotoPath(string $path): array
    {
        $imageInfo = @getimagesize($path);

        if (($imageInfo['mime'] ?? null) !== 'image/webp') {
            return [$path, false];
        }

        $source = @imagecreatefromwebp($path);

        if (! $source instanceof \GdImage) {
            return [$path, false];
        }

        $canvas = imagecreatetruecolor(imagesx($source), imagesy($source));

        if (! $canvas instanceof \GdImage) {
            imagedestroy($source);

            return [$path, false];
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagedestroy($source);

        $temporaryBase = tempnam(sys_get_temp_dir(), 'ningredy-tg-');

        if ($temporaryBase === false) {
            imagedestroy($canvas);

            return [$path, false];
        }

        $temporaryPath = $temporaryBase.'.jpg';
        @unlink($temporaryBase);
        $written = imagejpeg($canvas, $temporaryPath, 92);
        imagedestroy($canvas);

        if (! $written) {
            @unlink($temporaryPath);

            return [$path, false];
        }

        return [$temporaryPath, true];
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

    /** @param array<int, int> $messageIds */
    public function deleteMessages(int|string $chatId, array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter(
            array_map('intval', $messageIds),
            fn (int $messageId): bool => $messageId > 0,
        )));

        if ($messageIds === []) {
            return ['ok' => true, 'result' => true];
        }

        return $this->post('deleteMessages', [
            'chat_id' => $chatId,
            'message_ids' => array_slice($messageIds, 0, 100),
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
