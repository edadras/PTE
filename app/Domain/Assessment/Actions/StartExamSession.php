<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Services\ExamRunner;
use App\Domain\Identity\Models\Student;

/**
 * Entry point for "start the exam" from any surface. All the interesting work —
 * eligibility, snapshot, clock — is ExamRunner's; this exists so callers depend
 * on an action rather than reaching into a service.
 */
final class StartExamSession
{
    public function __construct(private readonly ExamRunner $runner) {}

    public function handle(Exam $exam, Student $student): ExamSession
    {
        return $this->runner->start($exam, $student);
    }
}
