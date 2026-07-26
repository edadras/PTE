<?php

declare(strict_types=1);

namespace App\Filament\Academy\Support;

use App\Domain\Identity\Actions\CreateStudent;
use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * CSV in and out for the student list.
 *
 * The Learning context owns a proper importer for questions; students have no
 * equivalent domain action yet, so this only *drives* CreateStudent row by row
 * and never writes a Student itself.
 */
final class StudentCsv
{
    private const HEADERS = [
        'student_code', 'first_name', 'last_name', 'email', 'phone',
        'locale', 'level', 'target_score', 'status', 'registered_at', 'last_active_at',
    ];

    public function __construct(private readonly CreateStudent $createStudent) {}

    /**
     * @return array{imported: int, errors: array<int, string>}
     */
    public function import(UploadedFile $file, ?int $classGroupId = null): array
    {
        Gate::authorize('students.import');

        $handle = fopen($file->getRealPath(), 'rb');

        if ($handle === false) {
            return ['imported' => 0, 'errors' => [__('panel.students.error.unreadable')]];
        }

        $imported = 0;
        $errors = [];
        $header = null;
        $line = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($header === null) {
                $header = array_map(
                    static fn (mixed $value): string => strtolower(trim((string) $value)),
                    $row,
                );

                continue;
            }

            $attributes = array_combine(
                array_slice($header, 0, count($row)),
                array_slice($row, 0, count($header)),
            );

            if ($attributes === false || blank($attributes['first_name'] ?? null)) {
                $errors[] = __('panel.students.error.row', ['line' => $line]);

                continue;
            }

            try {
                $this->createStudent->handle(CreateStudentData::fromArray([
                    ...$attributes,
                    'source' => StudentSource::Import->value,
                    'class_group_id' => $classGroupId,
                ]));

                $imported++;
            } catch (Throwable $e) {
                $errors[] = sprintf('%s: %s', __('panel.students.error.row', ['line' => $line]), $e->getMessage());
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'errors' => $errors];
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('students.export');

        $academyId = TenantContext::id();
        $filename = sprintf('students-%d-%s.csv', $academyId, now()->format('Ymd-His'));

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, self::HEADERS);

            Student::query()->orderBy('id')->chunk(500, function ($students) use ($out): void {
                foreach ($students as $student) {
                    fputcsv($out, [
                        $student->student_code,
                        $student->first_name,
                        $student->last_name,
                        $student->email,
                        $student->phone,
                        $student->locale,
                        $student->level,
                        $student->target_score,
                        $student->status->value,
                        $student->registered_at?->toDateTimeString(),
                        $student->last_active_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
