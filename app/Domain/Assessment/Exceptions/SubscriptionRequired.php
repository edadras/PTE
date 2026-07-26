<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

final class SubscriptionRequired extends AssessmentException
{
    public static function forStudent(int $studentId, int $freeAttemptsUsed = 0): self
    {
        $exception = new self("Student {$studentId} needs an active subscription to practise.");
        $exception->context = ['free_used' => $freeAttemptsUsed];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.subscription_required';
    }
}
