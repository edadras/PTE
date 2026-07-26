<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Events\StaffInvited;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invites a staff member to an academy by e-mail.
 *
 * The user record is global: if the person already works at another academy
 * they keep the same identity and simply gain a second membership.
 *
 * @see docs/01-multi-tenancy.md §5
 */
final class InviteStaffMember
{
    public function __construct(private readonly AssignRole $assignRole) {}

    public function handle(
        Academy $academy,
        string $email,
        Role|SystemRole|string $role = SystemRole::Manager,
        ?string $name = null,
        ?User $invitedBy = null,
    ): AcademyUserRole {
        $email = Str::lower(trim($email));

        $membership = DB::transaction(function () use ($academy, $email, $role, $name, $invitedBy): AcademyUserRole {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name ?? Str::before($email, '@'),
                    // Placeholder secret: the invitee sets a real one when the
                    // invitation is accepted.
                    'password' => Str::password(32),
                    'is_super_admin' => false,
                ]
            );

            if ($name !== null && blank($user->name)) {
                $user->forceFill(['name' => $name])->save();
            }

            return $this->assignRole->handle(
                $user,
                $academy,
                $role,
                MembershipStatus::Invited,
                $invitedBy,
            );
        });

        // Mandatory audit hook (docs/02 §7).
        StaffInvited::dispatch($membership);

        return $membership;
    }
}
