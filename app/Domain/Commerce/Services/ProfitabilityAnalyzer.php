<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Data\ProfitabilitySnapshot;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI cost against revenue, per academy, per month.
 *
 * This is the alarm from docs/09 §6: without it a loss is only discovered when
 * the vendor invoice lands, which is a month too late to do anything about.
 */
final class ProfitabilityAnalyzer
{
    /** Flag anything burning more than half of what it pays us (docs/09 §6). */
    public const DEFAULT_THRESHOLD = 0.5;

    /**
     * Fallback FX rate. Revenue is billed in Rials and AI is paid for in
     * dollars, so one of the two has to be converted for the ratio to mean
     * anything. Set pte.commerce.usd_to_irr from a real feed in production.
     */
    private const DEFAULT_USD_TO_IRR = 600_000;

    public function analyze(int $academyId, ?string $period = null): ProfitabilitySnapshot
    {
        $period = $period ?? CarbonImmutable::now()->format('Y-m');
        [$from, $to] = $this->boundaries($period);

        $revenueMinor = (int) DB::table('payments')
            ->where('academy_id', $academyId)
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
            ->whereBetween('paid_at', [$from, $to])
            ->sum(DB::raw('amount - refunded_amount'));

        $currency = (string) (DB::table('payments')
            ->where('academy_id', $academyId)
            ->whereBetween('paid_at', [$from, $to])
            ->value('currency') ?? 'IRR');

        return new ProfitabilitySnapshot(
            academyId: $academyId,
            period: $period,
            aiCostUsdCents: $this->aiCostUsdCents($academyId, $from, $to),
            revenueUsdCents: $this->toUsdCents($revenueMinor, $currency),
            revenueMinorUnits: $revenueMinor,
            revenueCurrency: $currency,
            threshold: $this->threshold(),
        );
    }

    /**
     * @return Collection<int, ProfitabilitySnapshot>
     */
    public function analyzeAll(?string $period = null): Collection
    {
        $period = $period ?? CarbonImmutable::now()->format('Y-m');

        return DB::table('academies')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn (mixed $id): ProfitabilitySnapshot => $this->analyze((int) $id, $period))
            ->values();
    }

    /**
     * @return Collection<int, ProfitabilitySnapshot>
     */
    public function unprofitable(?string $period = null): Collection
    {
        return $this->analyzeAll($period)
            ->filter(static fn (ProfitabilitySnapshot $s): bool => $s->isUnprofitable())
            ->values();
    }

    public function threshold(): float
    {
        return (float) config('pte.commerce.profitability_alert_ratio', self::DEFAULT_THRESHOLD);
    }

    public function usdToIrr(): int
    {
        return (int) config('pte.commerce.usd_to_irr', self::DEFAULT_USD_TO_IRR);
    }

    /**
     * Sum of `ai_requests.cost_usd` for the window.
     *
     * The AI domain owns that table; guarding on its existence keeps Commerce
     * installable and testable on its own.
     */
    private function aiCostUsdCents(int $academyId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if (! Schema::hasTable('ai_requests')) {
            return 0;
        }

        $costUsd = (float) DB::table('ai_requests')
            ->where('academy_id', $academyId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('cost_usd');

        return (int) round($costUsd * 100);
    }

    private function toUsdCents(int $minorUnits, string $currency): int
    {
        if (strtoupper($currency) === 'USD') {
            return $minorUnits;
        }

        if (Money::scaleFor($currency) !== 0) {
            // Any other decimal currency is treated as already dollar-like;
            // a real multi-currency book would need a rate table here.
            return $minorUnits;
        }

        $rate = max(1, $this->usdToIrr());

        return (int) round($minorUnits / $rate * 100);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function boundaries(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00');

        if ($start === false) {
            $start = CarbonImmutable::now()->startOfMonth();
        }

        return [$start->startOfMonth(), $start->endOfMonth()];
    }
}
