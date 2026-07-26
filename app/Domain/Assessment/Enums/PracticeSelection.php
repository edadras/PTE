<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * How the practice engine draws questions. Passed through to QuestionSelector,
 * which owns the actual query.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
enum PracticeSelection: string
{
    case Random = 'random';
    case Sequential = 'sequential';
    case Adaptive = 'adaptive';

    public function label(): string
    {
        return __('assessment.practice_selection.'.$this->value);
    }
}
