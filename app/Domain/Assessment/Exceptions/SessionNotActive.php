<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Exceptions;

use App\Domain\Assessment\Enums\SessionStatus;

final class SessionNotActive extends AssessmentException
{
    public static function is(SessionStatus $status): self
    {
        $exception = new self("Session is {$status->value} and accepts no further answers.");
        $exception->context = ['status' => $status->value];

        return $exception;
    }

    public function translationKey(): string
    {
        return 'assessment.errors.session_not_active';
    }
}
