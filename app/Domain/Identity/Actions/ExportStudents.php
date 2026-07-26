<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Support\CsvWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes the academy's student roster as a CSV on the tenant disk.
 *
 * Built on Reporting's CsvWriter, which is where the Excel realities are
 * handled once for the whole platform: UTF-8 BOM, CRLF line endings and
 * formula-injection neutralisation — a student can name themselves
 * `=HYPERLINK(...)` and it must open as text, not execute.
 *
 * Recorded as DataExported here rather than through an event: docs/02 §7 makes
 * every export of student data auditable, and this path produces no Report row
 * for the ReportExported listener to hang off.
 *
 * @see docs/02-roles-and-rbac.md §7
 */
final class ExportStudents
{
    public const DIRECTORY = 'exports';

    private const HEADERS = [
        'student_code', 'first_name', 'last_name', 'email', 'phone', 'locale',
        'level', 'target_score', 'status', 'source', 'registered_at', 'last_active_at',
    ];

    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  array{status?: StudentStatus|string, class_group_id?: int, level?: string}  $filters
     * @return string Path of the CSV, relative to the tenant disk.
     */
    public function handle(array $filters = []): string
    {
        $writer = CsvWriter::temporary('pte-students');

        try {
            $writer->headers(self::HEADERS);

            $this->query($filters)->chunkById(500, function ($students) use ($writer): void {
                foreach ($students as $student) {
                    $writer->write([
                        $student->student_code,
                        $student->first_name,
                        $student->last_name,
                        $student->email,
                        $student->phone,
                        $student->locale,
                        $student->level,
                        $student->target_score,
                        $student->status,
                        $student->source,
                        $student->registered_at,
                        $student->last_active_at,
                    ]);
                }
            });

            $path = sprintf(
                '%s/students-%s-%s.csv',
                self::DIRECTORY,
                now()->format('Ymd-His'),
                Str::lower(Str::random(6)),
            );

            Storage::disk('tenant')->put($path, $writer->contents());
        } finally {
            $writer->discard();
        }

        $this->recorder->record(AuditAction::DataExported, null, [], [
            'type' => 'students',
            'format' => 'csv',
            'params' => $filters,
            'row_count' => $writer->rowCount(),
            'file_path' => $path,
        ]);

        return $path;
    }

    /**
     * @param  array{status?: StudentStatus|string, class_group_id?: int, level?: string}  $filters
     * @return Builder<Student>
     */
    private function query(array $filters): Builder
    {
        $status = $filters['status'] ?? null;

        return Student::query()
            ->when($status !== null, static fn (Builder $query): Builder => $query->where(
                'status',
                $status instanceof StudentStatus ? $status->value : (string) $status,
            ))
            ->when(
                isset($filters['class_group_id']),
                static fn (Builder $query): Builder => $query->whereHas(
                    'classGroups',
                    static fn (Builder $groups): Builder => $groups->where('class_groups.id', (int) $filters['class_group_id']),
                ),
            )
            ->when(
                isset($filters['level']),
                static fn (Builder $query): Builder => $query->where('level', (string) $filters['level']),
            );
    }
}
