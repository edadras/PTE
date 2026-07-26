<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Database\Factories\DailyStatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academy_id
 * @property Carbon $date
 * @property string $metric
 * @property float $value
 * @property array<string, mixed>|null $meta
 *
 * @see docs/07-database-schema.md §11
 */
final class DailyStat extends Model
{
    use BelongsToAcademy;

    /** @use HasFactory<DailyStatFactory> */
    use HasFactory;

    protected $table = 'daily_stats';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * Deliberately not a `date` cast.
     *
     * Laravel writes date-cast attributes with the connection's full datetime
     * format, so the column ends up holding `2026-07-19 00:00:00` on SQLite.
     * The unique key and every dashboard lookup compare against `2026-07-19`,
     * and the mismatch shows up as duplicate rows rather than as an error.
     * Storing the date-only string keeps both sides honest.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): Carbon => Carbon::parse((string) $value)->startOfDay(),
            set: static fn (Carbon|string $value): string => ($value instanceof Carbon
                ? $value
                : Carbon::parse($value))->toDateString(),
        );
    }

    /** Models live under app/Domain, so the default factory guesser misses. */
    protected static function newFactory(): DailyStatFactory
    {
        return DailyStatFactory::new();
    }

    public function metricEnum(): ?StatMetric
    {
        return StatMetric::tryFrom($this->metric);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMetric(Builder $query, StatMetric|string $metric): Builder
    {
        return $query->where('metric', $metric instanceof StatMetric ? $metric->value : $metric);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Upsert one metric. Re-running an aggregation for a day must overwrite it,
     * never add a second row — a retried nightly job would otherwise double
     * every number on the dashboard.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function put(int $academyId, Carbon $date, StatMetric $metric, float $value, ?array $meta = null): self
    {
        /** @var self $stat */
        $stat = self::query()->updateOrCreate(
            [
                'academy_id' => $academyId,
                'date' => $date->toDateString(),
                'metric' => $metric->value,
            ],
            ['value' => $value, 'meta' => $meta],
        );

        return $stat;
    }
}
