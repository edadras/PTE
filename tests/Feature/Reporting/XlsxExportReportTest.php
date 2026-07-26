<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportJob;
use App\Domain\Reporting\Models\Report;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

final class XlsxExportReportTest extends TestCase
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
    public function a_student_export_in_xlsx_writes_a_real_workbook_to_the_tenant_disk(): void
    {
        Student::factory()->create(['first_name' => 'Reza', 'last_name' => 'Karimi']);
        Student::factory()->create(['first_name' => 'Sara', 'last_name' => 'Moradi']);

        $report = app(ExportReport::class)->handle(ReportType::Students, format: 'xlsx');

        $this->assertSame(ReportStatus::Ready, $report->status);
        $this->assertSame(2, $report->row_count);
        $this->assertSame('xlsx', $report->format);
        $this->assertStringEndsWith('.xlsx', (string) $report->file_path);

        $sheet = $this->sheetFor($report);

        $this->assertSame('id', $this->cellText($sheet, 'A1'));
        $this->assertStringContainsString('Reza', $sheet->asXML() ?: '');
        $this->assertStringContainsString('Sara', $sheet->asXML() ?: '');
    }

    #[Test]
    public function the_queued_job_builds_the_xlsx_registered_on_the_report_row(): void
    {
        Student::factory()->count(3)->create();

        $report = app(ExportReport::class)->queue(ReportType::Students, format: 'xlsx');

        (new GenerateReportJob((int) $this->academy->getKey(), (int) $report->getKey()))
            ->handle(app(ExportReport::class));

        $report->refresh();

        $this->assertSame(ReportStatus::Ready, $report->status);
        $this->assertSame(3, $report->row_count);
        $this->assertStringEndsWith('.xlsx', (string) $report->file_path);
        Storage::disk('tenant')->assertExists((string) $report->file_path);
    }

    #[Test]
    public function a_formula_shaped_cell_is_neutralised_in_xlsx_exactly_like_csv(): void
    {
        Student::factory()->create(['first_name' => '=cmd|/c calc']);

        $report = app(ExportReport::class)->handle(ReportType::Students, format: 'xlsx');

        $sheet = $this->sheetFor($report);

        $this->assertSame("'=cmd|/c calc", $this->cellText($sheet, 'C2'));
    }

    #[Test]
    public function an_unknown_format_is_rejected_before_a_report_row_exists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[pdf\] is not supported/');

        try {
            app(ExportReport::class)->handle(ReportType::Students, format: 'pdf');
        } finally {
            // Failing loudly is only half the contract — no orphan Pending row
            // may be left behind for the panel to render forever.
            $this->assertSame(0, Report::query()->count());
        }
    }

    private function sheetFor(Report $report): SimpleXMLElement
    {
        $contents = (string) Storage::disk('tenant')->get((string) $report->file_path);
        $temp = (string) tempnam(sys_get_temp_dir(), 'pte-xlsx-roundtrip');

        file_put_contents($temp, $contents);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temp) === true, 'The stored export is not a readable ZIP.');

        $raw = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($temp);

        $sheet = simplexml_load_string($raw);
        $this->assertInstanceOf(SimpleXMLElement::class, $sheet, 'sheet1.xml is not well-formed.');

        return $sheet;
    }

    private function cellText(SimpleXMLElement $sheet, string $reference): string
    {
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $matches = $sheet->xpath("//s:c[@r='{$reference}']/s:is/s:t");

        $this->assertNotEmpty($matches, "No inline string at {$reference}.");

        return (string) $matches[0];
    }
}
