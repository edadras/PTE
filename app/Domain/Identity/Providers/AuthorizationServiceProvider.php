<?php

declare(strict_types=1);

namespace App\Domain\Identity\Providers;

use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Policies\StudentPolicy;
use App\Domain\Identity\Support\PermissionCatalog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the two authorisation rules that cannot live in the permission tables:
 *
 *  1. a super admin passes every gate — the flag is a column on `users`, never
 *     a tenant role (docs/02 §1);
 *  2. `platform.*` abilities exist as gates even when no permission row does,
 *     so `withoutTenantScope()` and the platform panel fail closed for
 *     everybody else.
 *
 * Register in bootstrap/providers.php.
 *
 * @see docs/01-multi-tenancy.md §3 · docs/02-roles-and-rbac.md §1
 */
final class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Student::class, StudentPolicy::class);

        // Runs before every check, including the ones spatie registers.
        Gate::before(static function (?User $user, string $ability): ?bool {
            return $user?->isSuperAdmin() === true ? true : null;
        });

        foreach (PermissionCatalog::platformScoped() as $ability) {
            Gate::define($ability, static fn (?User $user): bool => $user?->isSuperAdmin() === true);
        }
    }
}
