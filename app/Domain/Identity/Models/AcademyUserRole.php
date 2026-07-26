<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Actions\AssignRole;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff membership of one user in one academy.
 *
 * @property int $academy_id
 * @property int $user_id
 * @property int $role_id
 * @property MembershipStatus $status
 *
 * @see docs/01-multi-tenancy.md §5
 */
final class AcademyUserRole extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $table = 'academy_user_roles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInvited(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Invited->value);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * Accepting an invitation must also grant the spatie role, which is only
     * written for active memberships — hence the round trip through the action.
     */
    public function accept(): self
    {
        return app(AssignRole::class)->handle(
            $this->user,
            $this->academy,
            $this->role,
            MembershipStatus::Active,
        );
    }
}
