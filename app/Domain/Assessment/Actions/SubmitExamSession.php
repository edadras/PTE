<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Services\ExamRunner;

final class SubmitExamSession
{
    public function __construct(private readonly ExamRunner $runner) {}

    /** @param bool $automatic true when the timer, not the student, ended it. */
    public function handle(ExamSession $session, bool $automatic = false): ExamSession
    {
        return $automatic || $session->hasExpired()
            ? $this->runner->expire($session)
            : $this->runner->submit($session);
    }
}
