<?php

namespace App\Http\Middleware;

use App\Services\Catalog\CustomerPricing;
use App\Services\Products\CatalogLocales;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // The shop's languages and the ones product text is translated into; a
            // missing translation falls back to the first shop language, then English.
            'catalogLocales' => fn (): array => [
                'storefront' => CatalogLocales::storefrontLocales(),
                'content' => array_keys(CatalogLocales::contentTranslations()),
            ],
            // The signed-in customer, for the account link and the mark on their own prices.
            'customer' => function (): ?array {
                $pricing = app(CustomerPricing::class);
                $customer = $pricing->customer();

                return $customer === null ? null : ['name' => $customer->name, 'group' => $pricing->group()?->name];
            },
        ];
    }
}
