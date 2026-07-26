<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permissions are platform-level: the catalogue is identical for every academy,
 * only the role -> permission wiring is tenant-specific.
 *
 * @property string $name
 * @property string|null $group
 * @property string $scope
 *
 * @see docs/02-roles-and-rbac.md §3
 */
final class Permission extends SpatiePermission
{
    protected $guarded = ['id'];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAcademyScoped(Builder $query): Builder
    {
        return $query->where('scope', PermissionCatalog::SCOPE_ACADEMY);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlatformScoped(Builder $query): Builder
    {
        return $query->where('scope', PermissionCatalog::SCOPE_PLATFORM);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function isPlatformScoped(): bool
    {
        return $this->scope === PermissionCatalog::SCOPE_PLATFORM;
    }

    public function displayName(): string
    {
        return (string) ($this->display_name ?? PermissionCatalog::labelFor($this->name));
    }

    public function groupLabel(): string
    {
        return PermissionCatalog::groupLabel((string) ($this->group ?? 'system'));
    }
}
