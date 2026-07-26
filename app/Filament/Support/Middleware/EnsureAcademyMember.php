<?php

declare(strict_types=1);

namespace App\Filament\Support\Middleware;

use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff account only reaches the academy resolved from the hostname.
 *
 * Being logged in on academy A's panel and typing academy B's hostname must not
 * work, even though `users` is a global table — the membership row is the
 * boundary (docs/01 §5).
 */
final class EnsureAcademyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $academy = TenantContext::get();

        abort_unless($user instanceof User, 403);
        abort_unless($academy instanceof Academy, 404);

        if ($user->isSuperAdmin()) {
            // A super admin may only be here through the audited support flow.
            abort_unless(
                Impersonation::isActive()
                    && Impersonation::payload()['academy_id'] === (int) $academy->getKey()
                    && ! Impersonation::hasExpired(),
                403,
            );

            return $next($request);
        }

        abort_unless($user->belongsToAcademy($academy), 403);

        return $next($request);
    }
}
