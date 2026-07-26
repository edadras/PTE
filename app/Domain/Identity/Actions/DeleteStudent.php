<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\StudentDeleted;
use App\Domain\Identity\Models\Student;

/**
 * Soft delete — answers, scores and purchases keep their referent through the
 * retention window (docs/12). The event is what writes the mandatory audit
 * entry (docs/02 §7).
 */
final class DeleteStudent
{
    public function handle(Student $student, ?int $actorId = null): void
    {
        if (! $student->trashed()) {
            $student->delete();
        }

        StudentDeleted::dispatch($student, $actorId);
    }
}
