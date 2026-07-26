<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ClassGroupStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Models\User;
use Database\Factories\ClassGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $academy_id
 * @property string $name
 * @property ClassGroupStatus $status
 *
 * @see docs/07-database-schema.md §4
 */
final class ClassGroup extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<ClassGroupFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ClassGroupStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): ClassGroupFactory
    {
        return ClassGroupFactory::new();
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'class_group_students')
            ->withPivot(['enrolled_at', 'academy_id'])
            ->withTimestamps();
    }

    /** Teachers assigned to this group beyond the primary `teacher_id`. */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'teacher_class_groups', 'class_group_id', 'user_id')
            ->withPivot('academy_id')
            ->withTimestamps();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ClassGroupStatus::Active->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTaughtBy(Builder $query, User $teacher): Builder
    {
        return $query->where(function (Builder $inner) use ($teacher): void {
            $inner
                ->where('teacher_id', $teacher->getKey())
                ->orWhereIn('id', $teacher->assignedClassGroupIds());
        });
    }

    public function hasCapacity(): bool
    {
        if ($this->capacity === null) {
            return true;
        }

        return $this->students()->count() < $this->capacity;
    }
}
