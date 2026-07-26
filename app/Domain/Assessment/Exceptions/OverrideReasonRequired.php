<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

/**
 * A grade change without a written reason is indefensible when a student
 * disputes it, so the domain refuses to record one.
 */
final class OverrideReasonRequired extends AssessmentException
{
    public static function forAnswer(int $answerId): self
    {
        $exception = new self("Overriding answer {$answerId} requires a non-empty reason.");
        $exception->context = ['answer_id' => $answerId];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.override_reason_required';
    }
}
