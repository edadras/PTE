<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

enum ReportType: string
{
    case Students = 'students';
    case Scores = 'scores';
    case ExamResults = 'exam_results';
    case PracticeSessions = 'practice_sessions';
    case ReportCard = 'report_card';
    case AcademyExport = 'academy_export';

    public function label(): string
    {
        return __("reports.type.{$this->value}");
    }

    /**
     * True when the file contains personal data about students, which makes
     * generating it a mandatory audit event (docs/02 §7).
     */
    public function containsPersonalData(): bool
    {
        return $this !== self::PracticeSessions;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            []
        );
    }
}
