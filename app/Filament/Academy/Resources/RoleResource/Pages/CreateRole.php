<?php

declare(strict_types=1);

namespace App\Filament\Academy\Resources\RoleResource\Pages;

use App\Domain\Identity\Models\Role;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Academy\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $permissions = RoleResource::permissionsFromForm($data);

        /** @var Role $role */
        $role = Role::query()->create([
            'academy_id' => TenantContext::id(),
            'name' => strtolower(trim((string) $data['name'])),
            'guard_name' => 'web',
            'display_name' => $data['display_name'] ?? null,
            'color' => $data['color'] ?? 'primary',
            'is_system' => false,
            'level' => (int) ($data['level'] ?? 10),
        ]);

        RoleResource::syncPermissions($role, $permissions);

        return $role;
    }
}
