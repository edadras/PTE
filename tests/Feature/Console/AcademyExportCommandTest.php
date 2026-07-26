<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Support\CsvWriter;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

final class AcademyExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create(['slug' => 'parsian']);
        $this->destination = storage_path('app/testing/parsian-export.zip');

        File::delete($this->destination);
    }

    protected function tearDown(): void
    {
        File::delete($this->destination);

        parent::tearDown();
    }

    #[Test]
    public function it_produces_an_archive_that_can_actually_be_read_back(): void
    {
        TenantContext::runFor($this->academy, function (): void {
            $student = Student::factory()->create(['first_name' => 'Reza', 'last_name' => 'Karimi']);

            Answer::factory()->count(2)->create([
                'student_id' => $student->getKey(),
                'session_type' => SessionType::Practice,
                'session_id' => 1,
                'score' => null,
            ]);
        });

        $this->artisan('academy:export', ['id' => 'parsian', '--path' => $this->destination])
            ->assertSuccessful();

        $this->assertFileExists($this->destination);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->destination) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame('pte-academy-export/1', $manifest['format']);
        $this->assertSame('parsian', $manifest['academy']['slug']);
        $this->assertNotSame('unknown', $manifest['schema_version']);

        // Every table named in the manifest is actually present in the archive.
        foreach ($manifest['restore_order'] as $table) {
            $this->assertNotFalse(
                $zip->locateName($manifest['tables'][$table]['file']),
                "The archive is missing {$table}."
            );
        }

        $this->assertSame(1, $manifest['tables']['academies']['rows']);
        $this->assertSame(1, $manifest['tables']['students']['rows']);
        $this->assertSame(2, $manifest['tables']['answers']['rows']);

        // Parents before children, so replaying the CSVs cannot break a key.
        $order = array_flip($manifest['restore_order']);
        $this->assertLessThan($order['students'], $order['academies']);
        $this->assertLessThan($order['answers'], $order['students']);

        $students = $this->rows((string) $zip->getFromName('students.csv'));

        $this->assertSame(Schema::getColumnListing('students'), $students[0]);
        $this->assertContains('Reza', $students[1]);

        // NULL survives the round trip as something other than an empty string.
        $answers = $this->rows((string) $zip->getFromName('answers.csv'));
        $scoreIndex = array_search('score', $answers[0], true);
        $this->assertSame('\\N', $answers[1][$scoreIndex]);

        $zip->close();
    }

    #[Test]
    public function it_exports_only_the_named_academys_rows(): void
    {
        $other = Academy::factory()->configured()->create(['slug' => 'other']);

        TenantContext::runFor($this->academy, fn () => Student::factory()->count(2)->create());
        TenantContext::runFor($other, fn () => Student::factory()->count(5)->create());

        $this->artisan('academy:export', ['id' => 'parsian', '--path' => $this->destination])
            ->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->destination);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame(2, $manifest['tables']['students']['rows']);
    }

    #[Test]
    public function the_export_is_recorded_in_the_platform_audit_log(): void
    {
        $this->artisan('academy:export', ['id' => 'parsian', '--path' => $this->destination])
            ->assertSuccessful();

        $entry = PlatformAuditLog::query()
            ->where('action', AuditAction::AcademyExported->value)
            ->forAcademy($this->academy)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->destination, $entry->payload['archive_path']);
    }

    #[Test]
    public function an_unknown_academy_fails_cleanly(): void
    {
        $this->artisan('academy:export', ['id' => 'nope'])->assertFailed();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(string $csv): array
    {
        $csv = str_replace(CsvWriter::BOM, '', $csv);

        return array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            array_values(array_filter(explode("\r\n", $csv), static fn (string $l): bool => $l !== '')),
        );
    }
}
