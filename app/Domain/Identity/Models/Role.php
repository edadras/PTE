<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role always belongs to one academy (`academy_id` is spatie's team key);
 * `academy_id = null` is reserved for platform-level roles.
 *
 * The tenant scope is *not* applied by BelongsToAcademy here — spatie's team
 * mode already filters every role lookup by the active academy, and the
 * nullable column would make a global scope fight it.
 *
 * @property int $id
 * @property int|null $academy_id
 * @property string $name
 * @property bool $is_system
 * @property int $level
 *
 * @see docs/02-roles-and-rbac.md
 */
final class Role extends SpatieRole
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'level' => 'integer',
        ];
    }

    public function academy(): BelongsTo
    {
        return $this->belongsTo(Academy::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AcademyUserRole::class)->withoutGlobalScope('academy');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfAcademy(Builder $query, Academy|int $academy): Builder
    {
        return $query->where('academy_id', $academy instanceof Academy ? $academy->getKey() : $academy);
    }

    public function systemRole(): ?SystemRole
    {
        return $this->is_system ? SystemRole::tryFromKey($this->name) : null;
    }

    public function displayName(): string
    {
        return (string) ($this->display_name ?? $this->systemRole()?->label() ?? $this->name);
    }

    public function isOwner(): bool
    {
        return $this->name === SystemRole::Owner->value;
    }

    /** Nobody may edit a role at or above their own level. */
    public function outranks(self $other): bool
    {
        return $this->level > $other->level;
    }
}
