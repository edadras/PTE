<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Data;

/**
 * The uniform result every scorer returns — deterministic or AI.
 *
 * `confidence` is 1.0 for algorithmic scorers because the rule is exact; the AI
 * gateway fills in a real number, and anything below the configured threshold is
 * routed to a teacher instead of the student.
 */
final readonly class ScoreResult
{
    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $feedback
     */
    public function __construct(
        public float $score,
        public float $maxScore,
        public array $breakdown = [],
        public array $feedback = [],
        public float $confidence = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $feedback
     */
    public static function make(
        float $score,
        float $maxScore,
        array $breakdown = [],
        array $feedback = [],
        float $confidence = 1.0,
    ): self {
        // Clamping here rather than in six scorers keeps negative marking and
        // rounding drift from ever producing an out-of-range grade.
        $max = max(0.0, $maxScore);

        return new self(
            round(max(0.0, min($score, $max)), 2),
            round($max, 2),
            $breakdown,
            $feedback,
            max(0.0, min(1.0, $confidence)),
        );
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $feedback
     */
    public static function zero(float $maxScore, array $breakdown = [], array $feedback = []): self
    {
        return self::make(0.0, $maxScore, $breakdown, $feedback);
    }

    /**
     * Convert a 0..1 ratio to the task's scale. Every deterministic scorer ends
     * in a ratio, so the scale conversion lives in exactly one place.
     *
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $feedback
     */
    public static function fromRatio(
        float $ratio,
        float $maxScore,
        array $breakdown = [],
        array $feedback = [],
    ): self {
        return self::make($ratio * $maxScore, $maxScore, $breakdown, $feedback);
    }

    public function percentage(): float
    {
        return $this->maxScore > 0.0 ? round($this->score / $this->maxScore * 100, 2) : 0.0;
    }

    public function isPerfect(): bool
    {
        return $this->maxScore > 0.0 && $this->score >= $this->maxScore;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'max_score' => $this->maxScore,
            'percentage' => $this->percentage(),
            'breakdown' => $this->breakdown,
            'feedback' => $this->feedback,
            'confidence' => $this->confidence,
        ];
    }
}
