<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Services\QuotaGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: copy the live Redis quota counters into `usage_counters`.
 *
 * Redis is fast but not durable enough to be the billing record. This job makes
 * the durable table the floor, so a Redis flush cannot hand an academy a free
 * month of unmetered AI, and reporting has something to read that is not the
 * hot path.
 *
 * @see docs/09-billing-and-plans.md §2
 */
final class RollUpUsageCounters implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ?int $onlyAcademyId = null,
        public readonly ?string $period = null,
    ) {}

    public function handle(): void
    {
        $academyIds = $this->onlyAcademyId !== null
            ? [$this->onlyAcademyId]
            : DB::table('academies')->whereNull('deleted_at')->pluck('id')->all();

        foreach ($academyIds as $academyId) {
            try {
                QuotaGuard::forAcademy((int) $academyId)->reconcile($this->period);
            } catch (Throwable $e) {
                // One academy's counters must never stop the roll-up for the rest.
                Log::error('Usage counter roll-up failed for an academy.', [
                    'academy_id' => $academyId,
                    'period' => $this->period,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['commerce', 'usage-rollup'];
    }
}
