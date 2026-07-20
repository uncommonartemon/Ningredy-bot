<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:set-webhook')]
#[Description('Register the Telegram webhook using the database proxy URL or TELEGRAM_PROXY_URL fallback')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramWebhookManager $manager): int
    {
        try {
            $webhookUrl = $manager->register();
        } catch (Throwable $exception) {
            $this->error('Telegram rejected the webhook: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Telegram webhook registered: {$webhookUrl}");

        return self::SUCCESS;
    }
}
