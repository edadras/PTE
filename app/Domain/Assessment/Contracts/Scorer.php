<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Contracts;

use App\Domain\Assessment\Data\ScoreResult;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Learning\Enums\QuestionType;

/**
 * A grading strategy for one or more question types.
 *
 * Implementations must be pure with respect to the answer: no persistence, no
 * queue, no side effects — ScoringDispatcher owns all of that. That is what
 * lets the ten deterministic types be unit-tested without a database.
 */
interface Scorer
{
    public function supports(QuestionType $type): bool;

    public function score(Answer $answer): ScoreResult;
}
