<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

final class DailyPracticeLimitReached extends AssessmentException
{
    public static function forStudent(int $studentId, int $limit, int $used): self
    {
        $exception = new self(
            "Student {$studentId} reached the daily practice cap ({$used}/{$limit})."
        );

        $exception->context = ['limit' => $limit, 'used' => $used];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.daily_limit_reached';
    }
}
