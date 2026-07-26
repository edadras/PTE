<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * One academy's AI cost against what it pays us, for one month.
 *
 * Both figures are normalised to USD cents so the ratio means something even
 * though we bill in Rials and pay AI vendors in dollars.
 */
final readonly class ProfitabilitySnapshot
{
    public function __construct(
        public int $academyId,
        public string $period,
        public int $aiCostUsdCents,
        public int $revenueUsdCents,
        public int $revenueMinorUnits,
        public string $revenueCurrency,
        public float $threshold,
    ) {}

    /** Null when the academy pays nothing yet (trial) — a ratio would be ∞. */
    public function ratio(): ?float
    {
        if ($this->revenueUsdCents <= 0) {
            return null;
        }

        return $this->aiCostUsdCents / $this->revenueUsdCents;
    }

    /**
     * A paying academy burning more than the threshold, or a non-paying one
     * that has already cost us real money, both need a human to look.
     */
    public function isUnprofitable(): bool
    {
        $ratio = $this->ratio();

        if ($ratio === null) {
            return $this->aiCostUsdCents > 0;
        }

        return $ratio > $this->threshold;
    }

    public function marginUsdCents(): int
    {
        return $this->revenueUsdCents - $this->aiCostUsdCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'academy_id' => $this->academyId,
            'period' => $this->period,
            'ai_cost_usd_cents' => $this->aiCostUsdCents,
            'revenue_usd_cents' => $this->revenueUsdCents,
            'revenue_minor_units' => $this->revenueMinorUnits,
            'revenue_currency' => $this->revenueCurrency,
            'ratio' => $this->ratio(),
            'threshold' => $this->threshold,
            'unprofitable' => $this->isUnprofitable(),
            'margin_usd_cents' => $this->marginUsdCents(),
        ];
    }
}
