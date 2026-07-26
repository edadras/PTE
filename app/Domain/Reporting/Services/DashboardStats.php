<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Reporting\Models\DailyStat;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The academy dashboard's numbers.
 *
 * Cheap by construction: every closed day is read from `daily_stats`, which the
 * nightly job wrote, and only *today* is computed from the live tables. Loading
 * a dashboard must never scan `answers` for a year — that table grows to
 * millions of rows per academy (docs/07 §11).
 *
 * Tenant-scoped throughout; call inside a resolved tenant.
 */
final class DashboardStats
{
    /** Today's numbers are recomputed at most this often. */
    private const TODAY_CACHE_SECONDS = 60;

    /** @var array<string, array<string, float>> */
    private array $memo = [];

    public function __construct(private readonly StatCollector $collector) {}

    /**
     * The widget set: students, today's practice, today's exams, AI requests,
     * average score, revenue, new users, active users.
     *
     * @return array<int, array<string, mixed>>
     */
    public function widgets(?Academy $academy = null, ?Carbon $on = null): array
    {
        $academy ??= TenantContext::require();
        $today = $on ?? $this->today($academy);
        $yesterday = $today->copy()->subDay();

        $current = $this->forDate($academy, $today);
        $previous = $this->forDate($academy, $yesterday);

        $widgets = [
            [StatMetric::StudentsTotal, 'students', 'integer'],
            [StatMetric::PracticeSessions, 'practice_today', 'integer'],
            [StatMetric::ExamSessions, 'exams_today', 'integer'],
            [StatMetric::AiRequests, 'ai_requests', 'integer'],
            [StatMetric::AverageScore, 'average_score', 'percentage'],
            [StatMetric::Revenue, 'revenue', 'money'],
            [StatMetric::StudentsNew, 'new_users', 'integer'],
            [StatMetric::StudentsActive, 'active_users', 'integer'],
        ];

        return array_map(
            fn (array $widget): array => $this->widget($widget[0], $widget[1], $widget[2], $current, $previous),
            $widgets,
        );
    }

    /**
     * Flat metric map, for callers that want the raw numbers.
     *
     * @return array<string, float>
     */
    public function forDate(?Academy $academy = null, ?Carbon $date = null): array
    {
        $academy ??= TenantContext::require();
        $date ??= $this->today($academy);

        $key = $academy->getKey().':'.$date->toDateString();

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $stored = $this->stored($academy, $date);

        // A day with no stored rows is either today (the nightly job has not run
        // for it yet) or a day the academy did not exist. Computing live covers
        // the first and costs nothing for the second.
        $values = $stored === []
            ? $this->live($academy, $date)
            : $stored;

        return $this->memo[$key] = $values;
    }

    /**
     * A per-day series for one metric — what a chart binds to.
     *
     * @return array<string, float> date (Y-m-d) => value
     */
    public function series(StatMetric $metric, Carbon $from, Carbon $to, ?Academy $academy = null): array
    {
        $academy ??= TenantContext::require();

        $rows = DailyStat::query()
            ->metric($metric)
            ->between($from, $to)
            ->orderBy('date')
            ->get(['date', 'value']);

        $series = [];

        foreach ($rows as $row) {
            $series[$row->date->toDateString()] = (float) $row->value;
        }

        // The current day is never in daily_stats yet; add it so a chart ending
        // "today" does not show a phantom drop to zero.
        $today = $this->today($academy);

        if ($today->betweenIncluded($from, $to) && ! array_key_exists($today->toDateString(), $series)) {
            $series[$today->toDateString()] = $this->forDate($academy, $today)[$metric->value] ?? 0.0;
        }

        return $series;
    }

    /**
     * One number for a range, rolled up the way the metric allows.
     */
    public function summary(StatMetric $metric, Carbon $from, Carbon $to, ?Academy $academy = null): float
    {
        return $metric->aggregation()->apply(
            array_values($this->series($metric, $from, $to, $academy))
        );
    }

    public function today(Academy $academy): Carbon
    {
        return Carbon::now($this->collector->timezoneFor($academy))->startOfDay();
    }

    /**
     * @param  array<string, float>  $current
     * @param  array<string, float>  $previous
     * @return array<string, mixed>
     */
    private function widget(StatMetric $metric, string $key, string $format, array $current, array $previous): array
    {
        $value = $current[$metric->value] ?? 0.0;
        $was = $previous[$metric->value] ?? 0.0;

        return [
            'key' => $key,
            'metric' => $metric->value,
            'label' => $metric->label(),
            'value' => $value,
            'previous' => $was,
            'delta' => round($value - $was, 4),
            'delta_percent' => $was > 0.0 ? round(($value - $was) / $was * 100, 1) : null,
            'format' => $format,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function stored(Academy $academy, Carbon $date): array
    {
        $rows = DailyStat::query()
            ->where('date', $date->toDateString())
            ->get(['metric', 'value']);

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row->metric] = (float) $row->value;
        }

        return $values;
    }

    /**
     * @return array<string, float>
     */
    private function live(Academy $academy, Carbon $date): array
    {
        $key = 'dashboard:live:'.$date->toDateString();

        /** @var array<string, float> $values */
        $values = cache()->remember(
            $key,
            self::TODAY_CACHE_SECONDS,
            fn (): array => $this->collector->collect($academy, $date),
        );

        return $values;
    }
}
