<?php

namespace App\Http\Middleware;

use App\Services\Products\CatalogLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        $locale = $request->session()->get('admin_locale', 'ru');
        app()->setLocale(is_string($locale) && CatalogLocales::supported($locale) ? $locale : 'ru');
        try {
            return $next($request);
        } finally {
            // The admin preference must not leak into the public API or bot.
            app()->setLocale($previous);
        }
    }
}
