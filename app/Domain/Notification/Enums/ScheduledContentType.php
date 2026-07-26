<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * The schedulable things listed in docs/05 §6.
 */
enum ScheduledContentType: string
{
    case PublishExam = 'publish_exam';
    case DailyPractice = 'daily_practice';
    case ExamReminder = 'exam_reminder';
    case WeeklyProgress = 'weekly_progress';
    case ReEngagement = 're_engagement';
    case Broadcast = 'broadcast';

    public function label(): string
    {
        return __("notifications.scheduled.{$this->value}");
    }

    /** Template key this type sends under, when it sends a message at all. */
    public function templateKey(): ?string
    {
        return match ($this) {
            self::DailyPractice => 'daily_nudge',
            self::ExamReminder => 'exam_reminder',
            self::WeeklyProgress => 'score_ready',
            self::ReEngagement => 'daily_nudge',
            default => null,
        };
    }
}
