<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Who may practise. The middle option ("a few free attempts, then subscribe") is
 * the one academies actually convert with, so it is a first-class case rather
 * than something bolted on top of a boolean.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
enum PracticeAccess: string
{
    case Everyone = 'all';
    case SubscribersOnly = 'subscribers';
    case FreeThenSubscribe = 'free_then_subscribe';

    public function label(): string
    {
        return __('assessment.practice_access.'.$this->value);
    }

    public function requiresSubscription(): bool
    {
        return $this !== self::Everyone;
    }
}
