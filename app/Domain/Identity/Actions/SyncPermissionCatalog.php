<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mirrors the code-declared permission catalogue into the `permissions` table.
 *
 * Idempotent: safe to run on deploy, in a seeder, or before seeding an
 * academy's roles.
 *
 * @see docs/02-roles-and-rbac.md §3
 */
final class SyncPermissionCatalog
{
    public function handle(string $guard = 'web'): int
    {
        $synced = 0;

        DB::transaction(function () use ($guard, &$synced): void {
            foreach (PermissionCatalog::all() as $group => $permissions) {
                foreach ($permissions as $name) {
                    Permission::query()->updateOrCreate(
                        ['name' => $name, 'guard_name' => $guard],
                        [
                            'group' => $group,
                            'scope' => PermissionCatalog::scopeOf($name),
                        ]
                    );

                    $synced++;
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $synced;
    }
}
