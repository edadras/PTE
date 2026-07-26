<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Assessment\Events\ExamDeleted;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;

/**
 * Mandatory audit (docs/02 §7): deleting an exam.
 */
final class RecordExamDeletion
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(ExamDeleted $event): void
    {
        $exam = $event->exam;

        $this->recorder->record(
            AuditAction::ExamDeleted,
            $exam,
            [],
            [
                'title' => $exam->title,
                'status' => $exam->status,
                'published_at' => $exam->published_at?->toIso8601String(),
                'deleted_by' => $event->deletedBy,
            ],
            (int) $exam->academy_id,
        );
    }
}
