<?php

declare(strict_types=1);

namespace App\Domain\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Re-scoring the same answers produced materially different numbers — the model
 * drifted, or a prompt changed underneath us (docs/06 §9).
 */
final class ScoringDriftDetected
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, array{answer_id: int, original: float, rescored: float, delta: float}>  $samples
     */
    public function __construct(
        public readonly int $academyId,
        public readonly string $taskKey,
        public readonly float $meanAbsoluteDeviation,
        public readonly float $threshold,
        public readonly int $sampleSize,
        public readonly array $samples = [],
    ) {}
}
