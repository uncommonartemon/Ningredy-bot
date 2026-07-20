<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Telegram\TelegramWebhookManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

#[Signature('telegram:sync-ngrok {--watch : Keep watching ngrok and update the webhook when its URL changes}')]
#[Description('Detect the local ngrok HTTPS tunnel and synchronize the Telegram webhook')]
class TelegramSyncNgrok extends Command
{
    public function handle(TelegramWebhookManager $manager): int
    {
        $watch = (bool) $this->option('watch');
        $lastSyncedUrl = null;
        $lastError = null;

        do {
            try {
                $proxyUrl = $this->detectProxyUrl();

                if ($proxyUrl !== $lastSyncedUrl) {
                    AppSetting::put(AppSetting::TELEGRAM_PROXY_URL, $proxyUrl);
                    $webhookUrl = $manager->register($proxyUrl);
                    $this->info("Telegram webhook synchronized: {$webhookUrl}");
                    $lastSyncedUrl = $proxyUrl;
                }

                $lastError = null;
            } catch (Throwable $exception) {
                if (! $watch) {
                    $this->error('Could not synchronize ngrok: '.$exception->getMessage());

                    return self::FAILURE;
                }

                if ($lastError !== $exception->getMessage()) {
                    $this->warn('Waiting for ngrok: '.$exception->getMessage());
                    $lastError = $exception->getMessage();
                }
            }

            if ($watch) {
                sleep(10);
            }
        } while ($watch);

        return self::SUCCESS;
    }

    private function detectProxyUrl(): string
    {
        $tunnels = Http::connectTimeout(2)
            ->timeout(4)
            ->get('http://127.0.0.1:4040/api/tunnels')
            ->throw()
            ->json('tunnels', []);

        if (! is_array($tunnels)) {
            throw new RuntimeException('ngrok returned an invalid tunnel list.');
        }

        $httpsTunnels = collect($tunnels)->filter(
            fn (mixed $tunnel): bool => is_array($tunnel)
                && str_starts_with((string) ($tunnel['public_url'] ?? ''), 'https://'),
        );
        $tunnel = $httpsTunnels->first(
            fn (array $tunnel): bool => str_contains((string) data_get($tunnel, 'config.addr'), '8000'),
        ) ?? $httpsTunnels->first();
        $proxyUrl = is_array($tunnel) ? (string) ($tunnel['public_url'] ?? '') : '';

        if ($proxyUrl === '') {
            throw new RuntimeException('no public HTTPS tunnel was found on http://127.0.0.1:4040.');
        }

        return rtrim($proxyUrl, '/');
    }
}
