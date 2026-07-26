<?php

declare(strict_types=1);

namespace App\Filament\Support\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only door into the platform panel.
 *
 * Gated on the `users.is_super_admin` column rather than a role, because a bug
 * in tenant scoping must never be able to become platform access (docs/02 §1).
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);

        return $next($request);
    }
}
