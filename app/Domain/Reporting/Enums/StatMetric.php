<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * The metrics `daily_stats` stores, one row per (academy, date, metric).
 *
 * Counters and averages share the table, which is why the column is decimal and
 * why aggregation is expressed as a mode: summing an average across a week is a
 * classic dashboard lie, so the enum states how each metric may be rolled up.
 *
 * @see docs/07-database-schema.md §11
 */
enum StatMetric: string
{
    case StudentsTotal = 'students_total';
    case StudentsNew = 'students_new';
    case StudentsActive = 'students_active';
    case PracticeSessions = 'practice_sessions';
    case ExamSessions = 'exam_sessions';
    case AnswersSubmitted = 'answers_submitted';
    case AiRequests = 'ai_requests';
    case AiCostUsd = 'ai_cost_usd';
    case AverageScore = 'average_score';
    case Revenue = 'revenue';
    case TelegramMessages = 'telegram_messages';

    public function label(): string
    {
        return __("reports.metric.{$this->value}");
    }

    /** How a range of days collapses into a single number. */
    public function aggregation(): StatAggregation
    {
        return match ($this) {
            self::StudentsTotal => StatAggregation::Last,
            self::AverageScore => StatAggregation::Mean,
            default => StatAggregation::Sum,
        };
    }

    public function isMonetary(): bool
    {
        return $this === self::Revenue;
    }

    /**
     * @return array<int, self>
     */
    public static function daily(): array
    {
        return self::cases();
    }
}
