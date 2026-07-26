<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Actions\ExportStudents;
use App\Domain\Identity\Actions\ImportStudents;
use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentAcquisition;
use App\Domain\Reporting\Support\CsvWriter;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StudentImportExportTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        Storage::fake('tenant');

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_clean_csv_imports_every_row_under_one_batch_id(): void
    {
        $path = $this->csv(
            "first_name,last_name,email,phone,target_score\n"
            ."Sara,Ahmadi,sara@example.com,09121234567,79\n"
            ."Reza,Karimi,reza@example.com,09121234568,65\n",
            withBom: true,
        );

        $report = app(ImportStudents::class)->handle($path);

        $this->assertTrue($report->isClean());
        $this->assertFalse($report->rolledBack);
        $this->assertSame(2, $report->totalRows);
        $this->assertSame(2, $report->imported);
        $this->assertCount(2, $report->studentIds);
        $this->assertNotSame('', $report->batchId);

        $sara = Student::query()->where('email', 'sara@example.com')->first();

        $this->assertNotNull($sara);
        $this->assertSame('Sara', $sara->first_name);
        $this->assertSame(StudentSource::Import, $sara->source);
        $this->assertSame((int) $this->academy->getKey(), (int) $sara->academy_id);

        // The batch id must be traceable, or the import can never be undone.
        $this->assertSame(
            2,
            StudentAcquisition::query()->where('payload->import_batch_id', $report->batchId)->count(),
        );
    }

    #[Test]
    public function bad_rows_are_reported_with_their_line_numbers_while_good_rows_land(): void
    {
        $path = $this->csv(
            "first_name,email\n"
            ."Sara,sara@example.com\n"
            .",missing-name@example.com\n"
            ."Reza,not-an-email\n",
        );

        $report = app(ImportStudents::class)->handle($path, ['all_or_nothing' => false]);

        $this->assertSame(3, $report->totalRows);
        $this->assertSame(1, $report->imported);
        $this->assertSame(2, $report->failedCount());

        $this->assertSame([3, 4], array_map(static fn ($error): int => $error->line, $report->errors));
        $this->assertStringContainsString('first_name', $report->errors[0]->messages[0]);
        $this->assertStringContainsString('email', $report->errors[1]->messages[0]);

        $this->assertSame(1, Student::query()->count());
    }

    #[Test]
    public function all_or_nothing_rolls_back_the_entire_file_on_a_single_bad_row(): void
    {
        $path = $this->csv(
            "first_name,email\n"
            ."Sara,sara@example.com\n"
            ."Reza,not-an-email\n",
        );

        $report = app(ImportStudents::class)->handle($path, ['all_or_nothing' => true]);

        $this->assertTrue($report->rolledBack);
        $this->assertSame(0, $report->imported);
        $this->assertSame(1, $report->failedCount());
        $this->assertSame(0, Student::query()->count());
    }

    #[Test]
    public function a_duplicate_student_code_is_rejected_per_row(): void
    {
        Student::factory()->create(['student_code' => 'STTAKEN1']);

        $path = $this->csv(
            "first_name,student_code\n"
            ."Sara,STTAKEN1\n"
            ."Reza,STFRESH1\n"
            ."Nima,STFRESH1\n",
        );

        $report = app(ImportStudents::class)->handle($path, ['all_or_nothing' => false]);

        $this->assertSame(1, $report->imported);
        $this->assertSame([2, 4], array_map(static fn ($error): int => $error->line, $report->errors));
        $this->assertStringContainsString('already taken', $report->errors[0]->messages[0]);
        $this->assertStringContainsString('earlier in this file', $report->errors[1]->messages[0]);
    }

    #[Test]
    public function a_completed_import_can_be_rolled_back_by_batch_id(): void
    {
        $path = $this->csv(
            "first_name,email\n"
            ."Sara,sara@example.com\n"
            ."Reza,reza@example.com\n",
        );

        $action = app(ImportStudents::class);
        $report = $action->handle($path);

        $this->assertSame(2, Student::query()->count());

        $this->assertSame(2, $action->rollback($report->batchId));

        // Soft deleted — anything already attached keeps its referent.
        $this->assertSame(0, Student::query()->count());
        $this->assertSame(2, Student::query()->withTrashed()->count());
    }

    #[Test]
    public function the_export_lands_on_the_tenant_disk_with_bom_and_crlf(): void
    {
        Student::factory()->count(2)->create();

        $path = app(ExportStudents::class)->handle();

        $this->assertStringStartsWith('exports/', $path);
        Storage::disk('tenant')->assertExists($path);

        $contents = (string) Storage::disk('tenant')->get($path);

        $this->assertStringStartsWith(CsvWriter::BOM, $contents);
        $this->assertStringContainsString("\r\n", $contents);

        $lines = array_values(array_filter(explode("\r\n", str_replace(CsvWriter::BOM, '', $contents))));

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith('student_code,first_name', $lines[0]);
    }

    #[Test]
    public function a_student_name_that_looks_like_a_formula_is_neutralised(): void
    {
        Student::factory()->create(['first_name' => '=cmd|/c calc']);
        Student::factory()->create(['last_name' => '@SUM(A1)']);

        $path = app(ExportStudents::class)->handle();
        $contents = (string) Storage::disk('tenant')->get($path);

        $this->assertStringContainsString("'=cmd|/c calc", $contents);
        $this->assertStringContainsString("'@SUM(A1)", $contents);
        $this->assertStringNotContainsString(',=cmd', $contents);
        $this->assertStringNotContainsString(',@SUM', $contents);
    }

    #[Test]
    public function exporting_student_data_is_audited(): void
    {
        Student::factory()->count(3)->create();

        $path = app(ExportStudents::class)->handle();

        $entry = ActivityLog::query()->forAction(AuditAction::DataExported->value)->first();

        $this->assertNotNull($entry);
        $this->assertSame('students', $entry->new_values['type']);
        $this->assertSame(3, (int) $entry->new_values['row_count']);
        $this->assertSame($path, $entry->new_values['file_path']);
    }

    private function csv(string $contents, bool $withBom = false): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pte-import-test');
        $this->files[] = $path;

        file_put_contents($path, ($withBom ? CsvWriter::BOM : '').$contents);

        return $path;
    }
}
