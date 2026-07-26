<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A learner inside one academy. Tenant-scoped on purpose: the same person at
 * two academies is two rows, so progress and purchases can never bleed across.
 *
 * @property int $academy_id
 * @property string $student_code
 * @property StudentStatus $status
 * @property StudentSource $source
 *
 * @see docs/01-multi-tenancy.md §5 · docs/07-database-schema.md §4
 */
final class Student extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['phone', 'email'];

    protected function casts(): array
    {
        return [
            'status' => StudentStatus::class,
            'source' => StudentSource::class,
            'target_score' => 'float',
            'subscription_expires_at' => 'datetime',
            'last_active_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): StudentFactory
    {
        return StudentFactory::new();
    }

    public function classGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'class_group_students')
            ->withPivot(['enrolled_at', 'academy_id'])
            ->withTimestamps();
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(StudentAcquisition::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(StudentProgress::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StudentStatus::Active->value);
    }

    /**
     * The teacher's second scope: only students in class groups assigned to
     * that teacher. Applied as a query scope so it cannot be forgotten.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScopedToTeacher(Builder $query, User $teacher): Builder
    {
        return $query->whereHas(
            'classGroups',
            fn (Builder $groups): Builder => $groups->whereIn(
                'class_groups.id',
                $teacher->assignedClassGroupIds()
            )
        );
    }

    public function isTaughtBy(User $teacher): bool
    {
        $groupIds = $teacher->assignedClassGroupIds();

        if ($groupIds === []) {
            return false;
        }

        return $this->classGroups()
            ->whereIn('class_groups.id', $groupIds)
            ->exists();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.(string) $this->last_name);
    }

    public function isActive(): bool
    {
        return $this->status === StudentStatus::Active;
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscription_expires_at !== null
            && $this->subscription_expires_at->isFuture();
    }

    public function touchLastActive(): void
    {
        $this->forceFill(['last_active_at' => now()])->saveQuietly();
    }
}
