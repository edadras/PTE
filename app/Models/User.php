<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\AcademyUserRole;
use App\Domain\Identity\Models\ClassGroup;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A global identity. Users are NOT tenant-scoped: one person can be a teacher
 * at academy A and support at academy B, and that is the same human being.
 *
 * `is_super_admin` is a column rather than a role, so that a bug in tenant
 * scoping can never escalate anyone to platform access.
 *
 * @property bool $is_super_admin
 *
 * @see docs/01-multi-tenancy.md §5 · docs/02-roles-and-rbac.md §1
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /** Memberships inside the academy that is currently active. */
    public function academyRoles(): HasMany
    {
        return $this->hasMany(AcademyUserRole::class);
    }

    /** Memberships across every academy — what the academy switcher needs. */
    public function allAcademyRoles(): HasMany
    {
        return $this->hasMany(AcademyUserRole::class)->withoutGlobalScope('academy');
    }

    public function academies(): BelongsToMany
    {
        return $this->belongsToMany(Academy::class, 'academy_user_roles')
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->withTimestamps()
            ->distinct();
    }

    public function ownedAcademies(): HasMany
    {
        return $this->hasMany(Academy::class, 'owner_user_id');
    }

    /** Class groups a teacher is assigned to — the teacher's second scope. */
    public function classGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'teacher_class_groups', 'user_id', 'class_group_id')
            ->withPivot('academy_id')
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function belongsToAcademy(Academy|int $academy): bool
    {
        $academyId = $academy instanceof Academy ? $academy->getKey() : $academy;

        return AcademyUserRole::query()
            ->withoutGlobalScope('academy')
            ->where('academy_id', $academyId)
            ->where('user_id', $this->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    public function membershipFor(Academy|int $academy): ?AcademyUserRole
    {
        $academyId = $academy instanceof Academy ? $academy->getKey() : $academy;

        return AcademyUserRole::query()
            ->withoutGlobalScope('academy')
            ->where('academy_id', $academyId)
            ->where('user_id', $this->getKey())
            ->first();
    }

    /**
     * Class group ids this user may reach inside the active academy.
     *
     * @return array<int, int>
     */
    public function assignedClassGroupIds(): array
    {
        $academyId = TenantContext::idOrNull();

        $query = $this->classGroups()->withoutGlobalScope('academy');

        if ($academyId !== null) {
            $query->wherePivot('academy_id', $academyId);
        }

        return $query
            ->pluck('class_groups.id')
            ->map(intval(...))
            ->all();
    }

    public function recordLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
