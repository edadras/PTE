<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

/**
 * The audit trail of a score: what the model said per criterion, what weight was
 * applied, and how that became a number on the academy's scale.
 *
 * Because the weighting happens here and not in the model (ADR-007), a rubric
 * change can re-derive every historical score without paying for a single token.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param  array<string, float>  $rawScores      criterion key => 0..100 as returned by the model
     * @param  array<string, float>  $weightedScores criterion key => raw * weight / 100
     * @param  array<string, int>  $weights          criterion key => weight
     * @param  array<int, string>  $missingCriteria  criteria the model did not score
     */
    public function __construct(
        public array $rawScores,
        public array $weightedScores,
        public array $weights,
        public float $rawTotal,
        public float $scaledScore,
        public int $scaleMin,
        public int $scaleMax,
        public string $rounding,
        public ?float $confidence = null,
        public array $missingCriteria = [],
    ) {}

    public function percentage(): float
    {
        return round($this->rawTotal, 2);
    }

    public function isComplete(): bool
    {
        return $this->missingCriteria === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw_scores' => $this->rawScores,
            'weighted_scores' => $this->weightedScores,
            'weights' => $this->weights,
            'raw_total' => round($this->rawTotal, 4),
            'scaled_score' => $this->scaledScore,
            'scale_min' => $this->scaleMin,
            'scale_max' => $this->scaleMax,
            'rounding' => $this->rounding,
            'confidence' => $this->confidence,
            'missing_criteria' => $this->missingCriteria,
        ];
    }
}
