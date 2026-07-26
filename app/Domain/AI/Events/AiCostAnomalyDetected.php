<?php

declare(strict_types=1);

namespace App\Domain\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An academy's AI spend is behaving in a way someone needs to look at today,
 * not at the end of the month (docs/06 §7.4).
 *
 * Raised rather than notified directly: the Ops context owns who gets told and
 * how.
 */
final class AiCostAnomalyDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $academyId,
        public readonly float $currentCostUsd,
        public readonly float $previousCostUsd,
        public readonly float $growthRatio,
        public readonly string $period,
        public readonly string $reason,
    ) {}

    public function growthPercent(): float
    {
        return round(($this->growthRatio - 1) * 100, 1);
    }
}
