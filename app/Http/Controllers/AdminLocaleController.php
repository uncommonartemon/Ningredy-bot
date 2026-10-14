<?php

namespace App\Http\Controllers;

use App\Services\Products\CatalogLocales;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminLocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless($request->user()?->canAccessPanel(Filament::getPanel('admin')), 403);
        abort_unless(CatalogLocales::supported($locale), 404);
        $request->session()->put('admin_locale', $locale);

        return redirect('/admin');
    }
}
