<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Answers are polymorphic over the two kinds of session. A plain string
 * discriminator (rather than Eloquent's morph map) keeps the reporting queries
 * readable and lets analytics run straight against the table.
 */
enum SessionType: string
{
    case Practice = 'practice';
    case Exam = 'exam';

    public function label(): string
    {
        return __('assessment.session_type.'.$this->value);
    }
}
