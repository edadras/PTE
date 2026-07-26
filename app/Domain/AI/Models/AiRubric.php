<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\AI\Data\RubricCriterion;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Exceptions\InvalidRubricException;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $academy_id  null = platform default
 * @property AiTaskKey $task_key
 * @property string $name
 * @property int $version
 * @property bool $is_active
 * @property array<int, array{key: string, label?: string, weight: int|float, guidance?: string}> $criteria
 * @property int $scale_min
 * @property int $scale_max
 * @property string $rounding
 */
final class AiRubric extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    public const ROUNDING_NEAREST = 'nearest';

    public const ROUNDING_FLOOR = 'floor';

    public const ROUNDING_CEIL = 'ceil';

    public const ROUNDING_TENTH = 'tenth';

    protected $table = 'ai_rubrics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'task_key' => AiTaskKey::class,
            'criteria' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
            'scale_min' => 'integer',
            'scale_max' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A rubric whose weights do not sum to 100 is quietly wrong for every
        // student it touches, so it must not reach the database at all.
        static::saving(function (self $rubric): void {
            $rubric->assertValid();
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?int $academyId = null): Builder
    {
        $academyId ??= TenantContext::check() ? TenantContext::id() : null;

        return $query
            ->withoutGlobalScope('academy')
            ->where(function (Builder $inner) use ($academyId): void {
                $inner->whereNull('academy_id');

                if ($academyId !== null) {
                    $inner->orWhere('academy_id', $academyId);
                }
            });
    }

    public static function resolveFor(AiTaskKey $task, ?int $academyId = null): ?self
    {
        return self::query()
            ->visibleTo($academyId)
            ->where('task_key', $task->value)
            ->where('is_active', true)
            ->orderByRaw('academy_id IS NULL')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array<int, RubricCriterion>
     */
    public function criterionObjects(): array
    {
        return array_map(
            static fn (array $row): RubricCriterion => RubricCriterion::fromArray($row),
            array_values($this->criteria ?? []),
        );
    }

    public function totalWeight(): int
    {
        return array_sum(array_map(
            static fn (RubricCriterion $c): int => $c->weight,
            $this->criterionObjects(),
        ));
    }

    public function assertValid(): void
    {
        $criteria = $this->criterionObjects();

        if ($criteria === []) {
            throw InvalidRubricException::emptyCriteria();
        }

        $keys = array_map(static fn (RubricCriterion $c): string => $c->key, $criteria);

        foreach (array_count_values($keys) as $key => $count) {
            if ($count > 1) {
                throw InvalidRubricException::duplicateCriterion((string) $key);
            }
        }

        if ($this->scale_max <= $this->scale_min) {
            throw InvalidRubricException::invalidScale($this->scale_min, $this->scale_max);
        }

        $total = array_sum(array_map(static fn (RubricCriterion $c): int => $c->weight, $criteria));

        if ($total !== 100) {
            throw InvalidRubricException::weightsDoNotSumTo100($total);
        }
    }

    /** Applies the academy's rounding preference to a scaled score. */
    public function round(float $value): float
    {
        return match ($this->rounding) {
            self::ROUNDING_FLOOR => (float) floor($value),
            self::ROUNDING_CEIL => (float) ceil($value),
            self::ROUNDING_TENTH => round($value, 1),
            default => (float) round($value),
        };
    }

    /**
     * Compact weight map handed to the model as {{rubric_weights}}.
     *
     * @return array<string, int>
     */
    public function weightMap(): array
    {
        $map = [];

        foreach ($this->criterionObjects() as $criterion) {
            $map[$criterion->key] = $criterion->weight;
        }

        return $map;
    }

    public function isPlatformDefault(): bool
    {
        return $this->academy_id === null;
    }
}
