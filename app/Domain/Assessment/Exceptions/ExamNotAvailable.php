<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

final class ExamNotAvailable extends AssessmentException
{
    private string $key = 'assessment.errors.exam_unavailable';

    public static function notPublished(int $examId): self
    {
        $exception = new self("Exam {$examId} is not published.");
        $exception->key = 'assessment.errors.exam_not_published';

        return $exception;
    }

    public static function outsideWindow(int $examId): self
    {
        $exception = new self("Exam {$examId} is outside its availability window.");
        $exception->key = 'assessment.errors.exam_window_closed';

        return $exception;
    }

    public static function attemptsExhausted(int $examId, int $allowed): self
    {
        $exception = new self("Exam {$examId} allows only {$allowed} attempt(s).");
        $exception->key = 'assessment.errors.exam_attempts_exhausted';
        $exception->context = ['allowed' => $allowed];

        return $exception;
    }

    public static function empty(int $examId): self
    {
        $exception = new self("Exam {$examId} has no sections with questions.");
        $exception->key = 'assessment.errors.exam_empty';

        return $exception;
    }

    public function translationKey(): string
    {
        return $this->key;
    }
}
