<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Enums\StatMetric;
use App\Domain\Reporting\Models\DailyStat;
use App\Domain\Reporting\Services\StatCollector;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Nightly roll-up into `daily_stats`, one row per (academy, date, metric).
 *
 * Runs per academy rather than as one platform sweep because every table it
 * reads is tenant-scoped; a single instance would only ever see whichever
 * tenant happened to be resolved (docs/10 §2).
 *
 * Defaults to *yesterday* in the academy's own timezone: at 02:00 UTC an
 * academy in Tehran is already at 05:30, and asking it to summarise "today"
 * would produce a day that is five hours old and still growing.
 */
final class AggregateDailyStats extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(int $academyId, public readonly ?string $date = null)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.reports', 'reports'));
    }

    public function handle(StatCollector $collector): void
    {
        $academy = TenantContext::get();

        if (! $academy instanceof Academy) {
            return;
        }

        $date = $this->resolveDate($academy, $collector);
        $values = $collector->collect($academy, $date);

        foreach ($values as $metric => $value) {
            $enum = StatMetric::tryFrom((string) $metric);

            if (! $enum instanceof StatMetric) {
                continue;
            }

            DailyStat::put((int) $academy->getKey(), $date, $enum, $value);
        }
    }

    /**
     * Fan out one job per active academy. Called from the scheduler.
     */
    public static function dispatchForAllActiveAcademies(?string $date = null): int
    {
        $count = 0;

        Academy::query()
            ->withoutGlobalScopes()
            ->where('status', AcademyStatus::Active->value)
            ->select(['id'])
            ->chunkById(100, function ($academies) use (&$count, $date): void {
                foreach ($academies as $academy) {
                    self::dispatch((int) $academy->getKey(), $date);
                    $count++;
                }
            });

        return $count;
    }

    private function resolveDate(Academy $academy, StatCollector $collector): Carbon
    {
        $timezone = $collector->timezoneFor($academy);

        if ($this->date !== null) {
            return Carbon::parse($this->date, $timezone)->startOfDay();
        }

        return Carbon::now($timezone)->subDay()->startOfDay();
    }
}
