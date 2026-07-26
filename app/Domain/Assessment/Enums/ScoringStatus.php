<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Where an individual answer sits in the grading pipeline.
 *
 * Deterministic answers jump straight to Scored inside the request; AI-backed
 * ones sit at Pending until the queue picks them up. ManualReview is the
 * fail-safe: an answer we could not grade is surfaced to a teacher rather than
 * silently scored zero.
 */
enum ScoringStatus: string
{
    case Pending = 'pending';
    case Scoring = 'scoring';
    case Scored = 'scored';
    case Failed = 'failed';
    case ManualReview = 'manual_review';

    public function label(): string
    {
        return __('assessment.scoring_status.'.$this->value);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Scored, self::Failed], true);
    }

    /** Still waiting on the queue — a session cannot be aggregated yet. */
    public function isPending(): bool
    {
        return in_array($this, [self::Pending, self::Scoring], true);
    }
}
