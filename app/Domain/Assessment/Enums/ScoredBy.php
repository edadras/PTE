<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Provenance of a score. Kept separate from ScoringStatus because a teacher
 * override has to remain distinguishable from a model result forever — that is
 * what makes a disputed grade defensible.
 */
enum ScoredBy: string
{
    case Ai = 'ai';
    case Teacher = 'teacher';
    case System = 'system';

    public function label(): string
    {
        return __('assessment.scored_by.'.$this->value);
    }
}
