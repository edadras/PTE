<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use App\Domain\Learning\Enums\AnswerKind;

final class InvalidAnswerShape extends AssessmentException
{
    public static function expected(AnswerKind $kind, string $detail): self
    {
        $exception = new self(
            "Answer does not match the {$kind->value} shape: {$detail}."
        );

        $exception->context = ['kind' => $kind->value, 'detail' => $detail];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.invalid_answer';
    }
}
