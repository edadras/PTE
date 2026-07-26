<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gives a user a role inside one academy.
 *
 * Two records are written on purpose: `academy_user_roles` is the membership
 * the panel manages, spatie's `model_has_roles` is the permission projection
 * used for checks. The spatie row is only written for an *active* membership,
 * so an invitation grants nothing until it is accepted.
 *
 * @see docs/02-roles-and-rbac.md
 */
final class AssignRole
{
    public function handle(
        User $user,
        Academy $academy,
        Role|SystemRole|string $role,
        MembershipStatus $status = MembershipStatus::Active,
        ?User $invitedBy = null,
    ): AcademyUserRole {
        return TenantContext::runFor($academy, function () use ($user, $academy, $role, $status, $invitedBy): AcademyUserRole {
            $resolved = $this->resolveRole($academy, $role);

            return DB::transaction(function () use ($user, $academy, $resolved, $status, $invitedBy): AcademyUserRole {
                /** @var AcademyUserRole $membership */
                $membership = AcademyUserRole::query()->updateOrCreate(
                    [
                        'academy_id' => $academy->getKey(),
                        'user_id' => $user->getKey(),
                        'role_id' => $resolved->getKey(),
                    ],
                    [
                        'status' => $status,
                        'invited_by' => $invitedBy?->getKey(),
                        'joined_at' => $status === MembershipStatus::Active ? now() : null,
                    ]
                );

                if ($status === MembershipStatus::Active) {
                    $user->assignRole($resolved);
                } else {
                    $user->removeRole($resolved);
                }

                return $membership;
            });
        });
    }

    private function resolveRole(Academy $academy, Role|SystemRole|string $role): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        $name = $role instanceof SystemRole ? $role->value : strtolower(trim($role));

        $resolved = Role::query()
            ->where('academy_id', $academy->getKey())
            ->where('name', $name)
            ->first();

        return $resolved ?? throw new InvalidArgumentException(
            "Role [{$name}] does not exist in academy [{$academy->getKey()}]."
        );
    }
}
