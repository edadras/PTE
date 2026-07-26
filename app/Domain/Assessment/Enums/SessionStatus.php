<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Lifecycle of both practice and exam sessions.
 *
 * The two flows share an enum but not every case: practice never goes through a
 * grading queue it can be blocked on, while an exam does. forPractice()/forExam()
 * expose the legal subset so the panel and the bot cannot offer a nonsense state.
 */
enum SessionStatus: string
{
    case InProgress = 'in_progress';

    // Practice only
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    // Exam only
    case Submitted = 'submitted';
    case Scoring = 'scoring';
    case Scored = 'scored';
    case Expired = 'expired';

    public function label(): string
    {
        return __('assessment.session_status.'.$this->value);
    }

    /** No further answers may be recorded once a session reaches one of these. */
    public function isTerminal(): bool
    {
        return $this !== self::InProgress;
    }

    public function isOpen(): bool
    {
        return $this === self::InProgress;
    }

    /** @return array<int, self> */
    public static function forPractice(): array
    {
        return [self::InProgress, self::Completed, self::Abandoned];
    }

    /** @return array<int, self> */
    public static function forExam(): array
    {
        return [self::InProgress, self::Submitted, self::Scoring, self::Scored, self::Expired];
    }
}
