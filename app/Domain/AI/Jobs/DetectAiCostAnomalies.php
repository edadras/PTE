<?php

declare(strict_types=1);

namespace App\Domain\AI\Jobs;

use App\Domain\AI\Events\AiCostAnomalyDetected;
use App\Domain\AI\Models\AiRequest;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level cost watchdog (docs/06 §7.4).
 *
 * The question it answers is the one that decides whether a customer is
 * profitable: which academy's spend just changed shape, today, rather than at
 * the end of the month when the invoice is already lost.
 *
 * Cross-tenant by nature, so it does not extend TenantAwareJob; it reads the
 * ledger with the tenant scope explicitly removed.
 */
final class DetectAiCostAnomalies implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Growth beyond this multiple of last month is worth a human look. */
    public const DEFAULT_GROWTH_RATIO = 3.0;

    /** Below this, percentage growth is noise — a $2 academy tripling is nothing. */
    public const MIN_SIGNIFICANT_COST_USD = 25.0;

    public function __construct(
        public readonly float $growthRatio = self::DEFAULT_GROWTH_RATIO,
        public readonly float $minCostUsd = self::MIN_SIGNIFICANT_COST_USD,
    ) {
        $this->onQueue((string) config('pte.queues.maintenance', 'maintenance'));
    }

    public function handle(): void
    {
        $currentStart = now()->startOfMonth();
        $previousStart = (clone $currentStart)->subMonth();

        $current = $this->spendByAcademy($currentStart, now());
        $previous = $this->spendByAcademy($previousStart, $currentStart);

        foreach ($current as $academyId => $cost) {
            if ($cost < $this->minCostUsd) {
                continue;
            }

            $before = $previous[$academyId] ?? 0.0;

            // A first month of spend has nothing to compare against; treat the
            // absolute figure as the signal instead of dividing by zero.
            $ratio = $before > 0.0 ? $cost / $before : INF;

            if ($ratio < $this->growthRatio) {
                continue;
            }

            AiCostAnomalyDetected::dispatch(
                (int) $academyId,
                round($cost, 4),
                round($before, 4),
                is_finite($ratio) ? round($ratio, 3) : 0.0,
                $currentStart->format('Y-m'),
                $before > 0.0 ? 'growth' : 'new_spend',
            );
        }
    }

    /**
     * @return array<int, float>
     */
    private function spendByAcademy(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return AiRequest::query()
            ->withoutGlobalScope('academy')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('academy_id')
            ->select('academy_id', DB::raw('SUM(cost_usd) as total_cost'))
            ->pluck('total_cost', 'academy_id')
            ->map(static fn (mixed $value): float => (float) $value)
            ->all();
    }
}
