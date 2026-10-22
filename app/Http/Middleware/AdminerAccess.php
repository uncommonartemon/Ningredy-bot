<?php

namespace App\Http\Middleware;

use App\Services\Admin\DatabaseBrowser;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The table editor is for the panel's admin sitting at this computer: never
 * through the public tunnel address, never for another account.
 */
final class AdminerAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(self::fromThisComputer($request), 404);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        if (! Filament::auth()->check()) {
            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl());
        }
        abort_unless(DatabaseBrowser::canAccess(), 403);

        return $next($request);
    }

    /** A request through the tunnel arrives forwarded, even though it reaches us from 127.0.0.1. */
    public static function fromThisComputer(Request $request): bool
    {
        return in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && ! $request->headers->has('X-Forwarded-For')
            && ! $request->headers->has('X-Forwarded-Host');
    }
}
