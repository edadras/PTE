<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Events\StudentDeleted;

/**
 * Mandatory audit (docs/02 §7): deleting a student.
 *
 * The student's name and contact details are deliberately not copied in — the
 * soft-deleted row still holds them, and duplicating personal data into an
 * append-only table would survive the erasure the deletion may be serving
 * (docs/12).
 */
final class RecordStudentDeletion
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(StudentDeleted $event): void
    {
        $student = $event->student;

        $this->recorder->record(
            AuditAction::StudentDeleted,
            $student,
            [],
            [
                'student_code' => $student->student_code,
                'status' => $student->status,
                'deleted_by' => $event->deletedBy,
            ],
            (int) $student->academy_id,
        );
    }
}
