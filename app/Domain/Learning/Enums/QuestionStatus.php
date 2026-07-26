<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * Content workflow: draft → pending_review → approved → published,
 * with rejected as the side exit.
 *
 * @see docs/05-modules-exams-practice.md §3
 */
enum QuestionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Published = 'published';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('learning.question_status.'.$this->value);
    }

    /** Only published questions are ever shown to a student. */
    public function isVisibleToStudents(): bool
    {
        return $this === self::Published;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Approved, self::Rejected],
            self::PendingReview => [self::Approved, self::Rejected, self::Draft],
            self::Approved => [self::Published, self::Rejected, self::Draft],
            self::Published => [self::Approved, self::Draft],
            self::Rejected => [self::Draft, self::PendingReview],
        };
    }
}
