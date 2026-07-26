<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Events\ExamDeleted;
use App\Domain\Assessment\Models\Exam;

/**
 * Soft delete — sessions already sat against this paper keep their referent.
 * The event is what writes the mandatory audit entry (docs/02 §7).
 */
final class DeleteExam
{
    public function handle(Exam $exam, ?int $actorId = null): void
    {
        if (! $exam->trashed()) {
            $exam->delete();
        }

        ExamDeleted::dispatch($exam, $actorId);
    }
}
