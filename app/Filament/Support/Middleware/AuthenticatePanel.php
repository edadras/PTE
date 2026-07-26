<?php

declare(strict_types=1);

namespace App\Filament\Support\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/**
 * Authentication without Filament's `canAccessPanel` fallback.
 *
 * Filament's own middleware aborts 403 for any user model that does not
 * implement `FilamentUser` unless the environment is `local`. `App\Models\User`
 * belongs to the Identity context and is not ours to change, and the real
 * gates here are EnsureSuperAdmin and EnsureAcademyMember, which run
 * immediately after this middleware. So this only establishes *who* is logged
 * in; *whether they may be here* is decided by those two.
 *
 * If User later implements Filament\Models\Contracts\FilamentUser, this class
 * can be dropped in favour of Filament\Http\Middleware\Authenticate.
 */
final class AuthenticatePanel extends Authenticate
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse(Filament::getAuthGuard());
    }
}
