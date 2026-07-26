<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Support\PermissionCatalog;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives a new academy its four system roles with the default permission matrix.
 *
 * Idempotent: re-running tops an existing role up to the current defaults
 * without touching custom roles the academy created itself.
 *
 * @see docs/02-roles-and-rbac.md §2, §4
 */
final class SeedAcademyRoles
{
    public function __construct(private readonly SyncPermissionCatalog $syncPermissionCatalog) {}

    /**
     * @return array<string, Role>
     */
    public function handle(Academy $academy, string $guard = 'web'): array
    {
        $this->syncPermissionCatalog->handle($guard);

        $roles = DB::transaction(function () use ($academy, $guard): array {
            $seeded = [];

            foreach (SystemRole::cases() as $systemRole) {
                $seeded[$systemRole->value] = $this->seedRole($academy, $systemRole, $guard);
            }

            return $seeded;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $roles;
    }

    private function seedRole(Academy $academy, SystemRole $systemRole, string $guard): Role
    {
        /** @var Role $role */
        $role = Role::query()->firstOrNew([
            'academy_id' => $academy->getKey(),
            'name' => $systemRole->value,
            'guard_name' => $guard,
        ]);

        $role->forceFill([
            'academy_id' => $academy->getKey(),
            'name' => $systemRole->value,
            'guard_name' => $guard,
            'color' => $systemRole->color(),
            'is_system' => true,
            'level' => $systemRole->level(),
        ])->save();

        $names = PermissionCatalog::defaultsForRole($systemRole->key());

        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $names)
            ->get();

        // Sync (not attach): the defaults are the contract for a *system* role,
        // so drift from a previous catalogue version is corrected here.
        $role->syncPermissions($permissions);

        return $role;
    }
}
