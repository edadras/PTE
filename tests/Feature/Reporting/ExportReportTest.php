<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportJob;
use App\Domain\Reporting\Models\Report;
use App\Domain\Reporting\Support\CsvWriter;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExportReportTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        Storage::fake('tenant');

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);
    }

    #[Test]
    public function a_student_export_writes_a_readable_csv_to_the_tenant_disk(): void
    {
        Student::factory()->count(3)->create();

        $report = app(ExportReport::class)->handle(ReportType::Students);

        $this->assertSame(ReportStatus::Ready, $report->status);
        $this->assertSame(3, $report->row_count);
        $this->assertStringStartsWith('exports/', (string) $report->file_path);

        Storage::disk('tenant')->assertExists((string) $report->file_path);

        $contents = Storage::disk('tenant')->get((string) $report->file_path);

        $this->assertStringStartsWith(CsvWriter::BOM, (string) $contents);

        $lines = array_values(array_filter(explode("\r\n", str_replace(CsvWriter::BOM, '', (string) $contents))));

        $this->assertCount(4, $lines);
        $this->assertSame(
            ['id', 'student_code', 'first_name', 'last_name', 'phone', 'email', 'status', 'source', 'target_score', 'registered_at', 'last_active_at'],
            str_getcsv($lines[0], ',', '"', '\\'),
        );
    }

    #[Test]
    public function the_export_expires_per_the_retention_configuration(): void
    {
        config()->set('pte.retention.exports', 1);

        Student::factory()->create();

        $report = app(ExportReport::class)->handle(ReportType::Students);

        $this->assertNotNull($report->expires_at);
        $this->assertSame(
            now()->addDay()->toDateString(),
            $report->expires_at->toDateString(),
        );
        $this->assertTrue($report->isDownloadable());
        $this->assertNotNull($report->temporaryUrl());
    }

    #[Test]
    public function generating_an_export_of_student_data_is_audited(): void
    {
        $user = User::factory()->create();
        Student::factory()->create();

        $report = app(ExportReport::class)->handle(ReportType::Students, [], $user);

        $entry = ActivityLog::query()
            ->forAction(AuditAction::DataExported->value)
            ->forSubject($report)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(ReportType::Students->value, $entry->new_values['type']);
        $this->assertSame((int) $user->getKey(), (int) $entry->new_values['requested_by']);
    }

    #[Test]
    public function queueing_an_export_registers_a_pending_report_and_dispatches_the_job(): void
    {
        Bus::fake();

        $report = app(ExportReport::class)->queue(ReportType::Scores, ['from' => now()->subWeek()->toDateString()]);

        $this->assertSame(ReportStatus::Pending, $report->status);
        $this->assertNull($report->file_path);

        Bus::assertDispatched(
            GenerateReportJob::class,
            fn (GenerateReportJob $job): bool => $job->reportId === (int) $report->getKey()
                && $job->academyId === (int) $this->academy->getKey(),
        );
    }

    #[Test]
    public function the_queued_job_fills_the_registered_report(): void
    {
        Student::factory()->count(2)->create();

        $report = app(ExportReport::class)->queue(ReportType::Students);

        (new GenerateReportJob((int) $this->academy->getKey(), (int) $report->getKey()))
            ->handle(app(ExportReport::class));

        $report->refresh();

        $this->assertSame(ReportStatus::Ready, $report->status);
        $this->assertSame(2, $report->row_count);
        Storage::disk('tenant')->assertExists((string) $report->file_path);
    }

    #[Test]
    public function a_cell_that_looks_like_a_spreadsheet_formula_is_neutralised(): void
    {
        Student::factory()->create(['first_name' => '=cmd|/c calc']);

        $report = app(ExportReport::class)->handle(ReportType::Students);
        $contents = (string) Storage::disk('tenant')->get((string) $report->file_path);

        $this->assertStringContainsString("'=cmd|/c calc", $contents);
        $this->assertStringNotContainsString(',=cmd', $contents);
    }

    #[Test]
    public function exports_never_cross_the_academy_boundary(): void
    {
        Student::factory()->count(2)->create();

        $other = Academy::factory()->configured()->create();

        $otherReport = TenantContext::runFor($other, function (): Report {
            Student::factory()->create();

            return app(ExportReport::class)->handle(ReportType::Students);
        });

        // Only the other academy's single student, not this academy's two.
        $this->assertSame(1, $otherReport->row_count);
        $this->assertSame((int) $other->getKey(), (int) $otherReport->academy_id);

        // And the report row itself is invisible from here.
        $this->assertSame(0, Report::query()->count());
    }
}
