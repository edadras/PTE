<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Reporting\Events\ReportExported;

/**
 * Mandatory audit (docs/02 §7): an export of student data.
 */
final class RecordDataExport
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(ReportExported $event): void
    {
        $report = $event->report;

        $this->recorder->record(
            $report->type->containsPersonalData() ? AuditAction::DataExported : AuditAction::ReportGenerated,
            $report,
            [],
            [
                'type' => $report->type->value,
                'format' => $report->format,
                'params' => $report->params ?? [],
                'row_count' => $report->row_count,
                'file_path' => $report->file_path,
                'expires_at' => $report->expires_at?->toIso8601String(),
                'requested_by' => $event->requestedBy,
            ],
            (int) $report->academy_id,
        );
    }
}
