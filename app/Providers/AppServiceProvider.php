<?php

namespace App\Providers;

use App\Services\Ai\AiSettings;
use App\Services\Products\ProductImageResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Gallery confirmation is intentionally kept in the resolver's browser
        // extractor while one product-search job runs. ProductImageStorage and
        // ProductImageCandidateDiscovery are separate object graphs, so a
        // transient resolver loses that provenance between discovery and the
        // downloader and applies the ordinary (higher) size threshold to a
        // structurally confirmed gallery. A scoped resolver is shared inside
        // one queue job and discarded before the next job.
        $this->app->scoped(ProductImageResolver::class);
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

        // Queue workers only run boot() once per process (see the comment on
        // applyStoredOpenAiApiKey below) - a fresh, random value here is
        // therefore stable for the lifetime of one worker process and
        // changes only when the worker is actually restarted. This lets
        // ProcessTelegramMessage tell "conversation continued from before
        // this worker last (re)started" apart from "still the same run",
        // so a fresh server start never carries forward stale AI
        // conversation memory (a draft_id mentioned hours ago, etc.) into
        // an unrelated new message.
        config(['app.boot_id' => (string) Str::uuid()]);
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
