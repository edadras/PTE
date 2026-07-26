<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\UsageMetric;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable copy of a Redis quota counter for one academy / period / metric.
 *
 * @property int $academy_id
 * @property string $period 'YYYY-MM'
 * @property UsageMetric $metric
 */
final class UsageCounter extends Model
{
    use BelongsToAcademy;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metric' => UsageMetric::class,
            'value' => 'integer',
            'limit_value' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    public function ratio(): ?float
    {
        if ($this->limit_value === null || $this->limit_value <= 0) {
            return null;
        }

        return $this->value / $this->limit_value;
    }

    /** @param  Builder<UsageCounter>  $query */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }
}
