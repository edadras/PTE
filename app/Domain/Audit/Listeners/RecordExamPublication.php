<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Assessment\Events\ExamPublished;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;

final class RecordExamPublication
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(ExamPublished $event): void
    {
        $exam = $event->exam;

        $this->recorder->record(
            AuditAction::ExamPublished,
            $exam,
            [],
            [
                'title' => $exam->getAttribute('title'),
                'status' => $exam->getAttribute('status'),
            ],
            (int) $exam->academy_id,
        );
    }
}
