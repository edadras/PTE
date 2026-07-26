<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Events\ReportExported;
use App\Domain\Reporting\Jobs\GenerateReportJob;
use App\Domain\Reporting\Models\Report;
use App\Domain\Reporting\Services\ReportDataSource;
use App\Domain\Reporting\Support\CsvWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Produces a CSV of students, scores or exam results on the tenant disk and
 * hands back a pre-signed link.
 *
 * The file lands under `exports/`, whose lifecycle rule (docs/10 §4) deletes it
 * after `pte.retention.exports` days; `expires_at` on the row mirrors that so
 * the application stops handing out links even where the bucket rule has not
 * fired yet. Belt and braces on purpose — this file contains a whole academy's
 * personal data.
 *
 * @see docs/07-database-schema.md §10 · docs/10-infrastructure-and-ops.md §4
 */
final class ExportReport
{
    public const DIRECTORY = 'exports';

    public function __construct(private readonly ReportDataSource $source) {}

    /**
     * Generate now. Suitable for the console and for small result sets; the
     * panel should use queue() instead.
     *
     * @param  array<string, mixed>  $params
     */
    public function handle(
        ReportType $type,
        array $params = [],
        User|int|null $requestedBy = null,
        string $format = 'csv',
    ): Report {
        $report = $this->pending($type, $params, $requestedBy, $format);

        return $this->generate($report);
    }

    /**
     * Register the request and let the reports queue do the work.
     *
     * @param  array<string, mixed>  $params
     */
    public function queue(
        ReportType $type,
        array $params = [],
        User|int|null $requestedBy = null,
        string $format = 'csv',
    ): Report {
        $report = $this->pending($type, $params, $requestedBy, $format);

        GenerateReportJob::dispatch((int) $report->academy_id, (int) $report->getKey());

        return $report;
    }

    /**
     * Fill a registered report. Idempotent enough to be retried: a second run
     * overwrites the object at the same path.
     */
    public function generate(Report $report): Report
    {
        $report->forceFill(['status' => ReportStatus::Generating])->save();

        $writer = CsvWriter::temporary('pte-report');

        try {
            $writer->headers($this->source->headers($report->type));
            $writer->writeAll($this->source->rows($report->type, $report->params ?? []));
            $writer->close();

            $path = $this->pathFor($report);

            Storage::disk(Report::DISK)->put($path, (string) file_get_contents($writer->path()));

            $report->forceFill([
                'file_path' => $path,
                'file_size' => (int) filesize($writer->path()),
                'row_count' => $writer->rowCount(),
                'status' => ReportStatus::Ready,
                'generated_at' => now(),
                'expires_at' => now()->addDays($this->retentionDays()),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $report->forceFill([
                'status' => ReportStatus::Failed,
                'error' => Str::limit($e->getMessage(), 1000, ''),
            ])->save();

            throw $e;
        } finally {
            $writer->discard();
        }

        ReportExported::dispatch($report, $report->requested_by);

        return $report;
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('pte.retention.exports', 1));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function pending(ReportType $type, array $params, User|int|null $requestedBy, string $format): Report
    {
        /** @var Report $report */
        $report = Report::query()->create([
            'academy_id' => TenantContext::id(),
            'type' => $type,
            'format' => $format,
            'params' => $params,
            'status' => ReportStatus::Pending,
            'requested_by' => $requestedBy instanceof User ? $requestedBy->getKey() : $requestedBy,
        ]);

        return $report;
    }

    /**
     * The disk root is already `academies/{id}` (TenantContext), so this path is
     * relative to the tenant and cannot address another academy's objects.
     */
    private function pathFor(Report $report): string
    {
        return sprintf(
            '%s/%s-%s-%d.%s',
            self::DIRECTORY,
            $report->type->value,
            now()->format('Ymd-His'),
            $report->getKey(),
            $report->format === '' ? 'csv' : $report->format,
        );
    }
}
