<?php

declare(strict_types=1);

namespace App\Filament\Support\Middleware;

use App\Filament\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a support session the moment its 30 minute window is over.
 *
 * Registered on the academy panel, so every panel request re-checks; the window
 * cannot be extended by staying on one page.
 *
 * @see docs/02-roles-and-rbac.md §2
 */
final class EnforceImpersonationWindow
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::isActive() && Impersonation::hasExpired()) {
            Impersonation::end('expired');

            return redirect()->guest(
                route('filament.panel.auth.login')
            )->with('status', __('panel.impersonation.expired'));
        }

        return $next($request);
    }
}
