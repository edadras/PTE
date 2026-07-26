<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * How an exam section decides which questions a student actually sees.
 *
 * @see docs/05-modules-exams-practice.md §5
 */
enum SelectionMode: string
{
    /** Hand-picked from the bank; every student sees the same paper. */
    case Manual = 'manual';

    /** Filtered draw from the bank at session start (type, difficulty, bank). */
    case Random = 'random';

    /** A candidate pool of N; each student receives a random subset — anti-cheating. */
    case Pool = 'pool';

    public function label(): string
    {
        return __('assessment.selection_mode.'.$this->value);
    }

    /** Manual sections keep an explicit exam_questions row per question. */
    public function usesExplicitQuestions(): bool
    {
        return $this !== self::Random;
    }
}
