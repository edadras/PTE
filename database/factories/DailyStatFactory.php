<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Reporting\Models\DailyStat;
use Database\Factories\Concerns\ResolvesAcademy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DailyStat>
 */
final class DailyStatFactory extends Factory
{
    use ResolvesAcademy;

    protected $model = DailyStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => $this->resolveAcademy(),
            'date' => now()->toDateString(),
            'metric' => StatMetric::PracticeSessions->value,
            'value' => $this->faker->numberBetween(0, 200),
        ];
    }

    public function metric(StatMetric $metric, float $value): self
    {
        return $this->state(fn (): array => ['metric' => $metric->value, 'value' => $value]);
    }

    public function on(Carbon|string $date): self
    {
        return $this->state(fn (): array => [
            'date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }
}
