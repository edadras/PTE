<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Spatie\Permission\DefaultTeamResolver;

/*
|--------------------------------------------------------------------------
| spatie/laravel-permission — team mode
|--------------------------------------------------------------------------
|
| The "team" of this platform is the academy: a role only ever means something
| inside one tenant. TenantContext::set() pushes the academy id into the
| PermissionRegistrar, so every permission check is automatically scoped.
|
| @see docs/02-roles-and-rbac.md §6
|
*/

return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null, // default 'role_id'
        'permission_pivot_key' => null, // default 'permission_id'
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'academy_id',
    ],

    'register_permission_check_method' => true,

    'register_octane_reset_listener' => false,

    'events_enabled' => false,

    'teams' => true,

    'team_resolver' => DefaultTeamResolver::class,

    'use_passport_client_credentials' => false,

    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
