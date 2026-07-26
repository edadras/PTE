<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Models\Report;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a registered report off the request cycle.
 *
 * On the low-priority `reports` queue with a long timeout (docs/10 §2): an
 * export of 50k answers must never sit in front of a student waiting for a
 * message to be delivered.
 *
 * The output format (csv or xlsx) is not job state on purpose — it is read
 * from reports.format by ExportReport::generate(), so a report queued as XLSX
 * before a deploy is still built as XLSX by the new worker.
 */
final class GenerateReportJob extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(int $academyId, public readonly int $reportId)
    {
        parent::__construct($academyId);

        $this->onQueue((string) config('pte.queues.reports', 'reports'));
    }

    public function handle(ExportReport $export): void
    {
        $report = Report::query()->find($this->reportId);

        if (! $report instanceof Report || $report->status === ReportStatus::Ready) {
            return;
        }

        $export->generate($report);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Report generation failed.', [
            'academy_id' => $this->academyId,
            'report_id' => $this->reportId,
            'error' => $exception?->getMessage(),
        ]);

        Report::query()
            ->withoutGlobalScope('academy')
            ->whereKey($this->reportId)
            ->update([
                'status' => ReportStatus::Failed->value,
                'error' => Str::limit($exception?->getMessage() ?? 'unknown', 1000, ''),
            ]);
    }
}
