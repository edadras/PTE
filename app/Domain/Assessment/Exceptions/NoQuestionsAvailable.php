<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

final class NoQuestionsAvailable extends AssessmentException
{
    public static function for(string $target): self
    {
        $exception = new self("No published questions available for {$target}.");
        $exception->context = ['target' => $target];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.no_questions';
    }
}
