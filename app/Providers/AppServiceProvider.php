<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

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

    }
}
