<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The tenant audit trail. **Append-only from the academy's perspective.**
 *
 * Why the model refuses to update or delete at all:
 *
 *  - the people this table records are the same people who administer the
 *    academy. A trail its subject can quietly edit is worth nothing in the one
 *    situation it exists for — a dispute over a changed grade, a deleted
 *    student, or an export of somebody's data (docs/02 §7);
 *  - "delete this log line" is never a legitimate product feature, so any code
 *    path that tries is a bug or an attack, and failing loudly is the only
 *    useful response;
 *  - retention is a *platform* decision, not a tenant one. The 12-month window
 *    is enforced by the Ops sweep, which prunes with the query builder
 *    (`ActivityLog::pruneOlderThan()`) rather than through this model, so the
 *    escape hatch lives in one reviewable place instead of everywhere.
 *
 * There is deliberately no `SoftDeletes`, no `updated_at`, and no `$fillable`
 * path to `created_at`.
 *
 * @property int $id
 * @property int $academy_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property Carbon|null $created_at
 *
 * @see docs/02-roles-and-rbac.md §7
 */
final class ActivityLog extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Slam the door on both mutation paths. Eloquent routes every update through
     * performUpdate() and every delete through delete(), so overriding these two
     * covers save(), touch(), forceFill()->save() and $model->delete() alike.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('activity_logs is append-only; an audit entry may never be modified.');
    }

    public function delete(): bool
    {
        throw new LogicException('activity_logs is append-only; use ActivityLog::pruneOlderThan() for retention.');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo(type: 'subject_type', id: 'subject_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * The one sanctioned removal path: platform retention, expressed as a
     * builder delete so it never travels through a model instance and can be
     * grepped for in review.
     */
    public static function pruneOlderThan(Carbon $before, ?int $academyId = null, int $chunk = 1000): int
    {
        $deleted = 0;

        do {
            $affected = self::query()
                ->withoutGlobalScope('academy')
                ->when($academyId !== null, fn (Builder $q): Builder => $q->where('academy_id', $academyId))
                ->where('created_at', '<', $before)
                ->limit($chunk)
                ->delete();

            $deleted += $affected;
        } while ($affected > 0);

        return $deleted;
    }
}
