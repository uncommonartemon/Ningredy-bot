<?php

namespace App\Services\Telegram;

use App\Models\AppSetting;
use InvalidArgumentException;

class TelegramWebhookManager
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function configuredProxyUrl(): string
    {
        return rtrim((string) AppSetting::valueFor(
            AppSetting::TELEGRAM_PROXY_URL,
            config('services.telegram.proxy_url'),
        ), '/');
    }

    public function register(?string $proxyUrl = null): string
    {
        $proxyUrl = rtrim(trim($proxyUrl ?? $this->configuredProxyUrl()), '/');

        if ($proxyUrl === '') {
            throw new InvalidArgumentException('Укажите публичный HTTPS URL прокси.');
        }

        if (filter_var($proxyUrl, FILTER_VALIDATE_URL) === false || parse_url($proxyUrl, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('Proxy URL должен быть корректным HTTPS-адресом.');
        }

        $webhookUrl = str_ends_with($proxyUrl, '/api/telegram/webhook')
            ? $proxyUrl
            : $proxyUrl.'/api/telegram/webhook';

        $this->telegram->setWebhook($webhookUrl);
        $this->telegram->setCommands();

        return $webhookUrl;
    }
}
