<?php

namespace App\Providers;

use App\Services\Ai\AiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            Http::globalOptions([
                'curl' => [
                    CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
                ],
            ]);
        }

        $this->applyStoredOpenAiApiKey();
    }

    /**
     * A key saved in Filament (AiSettingsPage) overrides OPENAI_API_KEY from
     * .env. Queue workers only run boot() once per process, so AiSettingsPage
     * restarts the queue after every save to pick up a changed key promptly.
     */
    private function applyStoredOpenAiApiKey(): void
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }

            $key = $this->app->make(AiSettings::class)->openAiApiKey();

            if ($key !== null) {
                config(['ai.providers.openai.key' => $key]);
            }
        } catch (Throwable) {
            // Database not reachable yet (e.g. during initial setup) - keep the .env key.
        }
    }
}
