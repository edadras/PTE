<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\StaffRemoved;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a person's staff relationship with one academy: every membership row and
 * every spatie role projection goes, in one transaction.
 *
 * The user record itself survives — it is global (docs/01 §5), and the same
 * person may still work at another academy.
 */
final class RemoveStaffMember
{
    public function handle(User $user, Academy $academy, ?User $removedBy = null): void
    {
        TenantContext::runFor($academy, function () use ($user, $academy, $removedBy): void {
            $memberships = AcademyUserRole::query()
                ->where('user_id', $user->getKey())
                ->with('role')
                ->get();

            if ($memberships->isEmpty()) {
                return;
            }

            $roleNames = [];

            DB::transaction(function () use ($user, $memberships, &$roleNames): void {
                foreach ($memberships as $membership) {
                    $role = $membership->role;

                    if ($role instanceof Role) {
                        $roleNames[] = (string) $role->name;
                        $user->removeRole($role);
                    }

                    $membership->delete();
                }
            });

            StaffRemoved::dispatch($user, $academy, $roleNames, $removedBy?->getKey());
        });
    }
}
