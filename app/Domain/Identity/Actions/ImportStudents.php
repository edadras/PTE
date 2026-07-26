<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Identity\Data\StudentImportReport;
use App\Domain\Identity\Enums\StudentSource;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Identity\Models\StudentAcquisition;
use App\Domain\Learning\Data\ImportRowError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bulk student import from a delimited file.
 *
 * Same construction as Learning's ImportQuestions, for the same reasons:
 * fgetcsv rather than a spreadsheet dependency, a streamed read so a 10k-row
 * file costs constant memory, and every failure reported with its line number —
 * the person fixing the spreadsheet needs to know *which* line, not that "the
 * import failed".
 *
 * The batch id is carried on each student's acquisition payload rather than a
 * dedicated column, so an import can be rolled back later without widening the
 * students table.
 *
 * @see docs/07-database-schema.md §4
 */
final class ImportStudents
{
    /** Columns the importer understands; anything else is reported, not guessed at. */
    private const COLUMNS = [
        'first_name', 'last_name', 'email', 'phone', 'locale', 'level',
        'target_score', 'status', 'source', 'student_code', 'class_group_id',
    ];

    public function __construct(private readonly CreateStudent $createStudent) {}

    /**
     * All-or-nothing is opt-in (unlike ImportQuestions): the public API's
     * import endpoint promises "good rows land, bad rows are reported", and
     * both surfaces share this implementation.
     *
     * @param  string  $path  A local filesystem path, or a path on the tenant disk.
     * @param  array{
     *     all_or_nothing?: bool,
     *     delimiter?: string,
     *     batch_id?: string,
     *     import_id?: string,
     *     class_group_id?: int|null,
     *     source?: StudentSource
     * }  $options
     */
    public function handle(string $path, array $options = []): StudentImportReport
    {
        $allOrNothing = $options['all_or_nothing'] ?? false;
        $batchId = $options['batch_id'] ?? $options['import_id'] ?? (string) Str::uuid();

        [$localPath, $temporary] = $this->localise($path);

        if ($localPath === null) {
            return new StudentImportReport($batchId, 0, 0, [
                new ImportRowError(0, [sprintf('Unable to read [%s].', $path)]),
            ], true);
        }

        try {
            $rows = $this->readRows($localPath, $options['delimiter'] ?? null);
        } finally {
            if ($temporary) {
                @unlink($localPath);
            }
        }

        /** @var array<int, ImportRowError> $errors */
        $errors = [];
        $prepared = [];
        $seenCodes = [];

        foreach ($rows as $line => $row) {
            $messages = $this->validateRow($row, $seenCodes);

            if ($messages !== []) {
                $errors[] = new ImportRowError($line, $messages, null, $row);

                continue;
            }

            $prepared[] = [$line, $this->toStudentData($row, $options, $batchId, $line)];
        }

        if ($errors !== [] && $allOrNothing) {
            return new StudentImportReport($batchId, count($rows), 0, $errors, true);
        }

        $created = [];

        try {
            DB::transaction(function () use ($prepared, &$created, &$errors, $allOrNothing): void {
                foreach ($prepared as [$line, $data]) {
                    try {
                        $created[] = (int) $this->createStudent->handle($data)->getKey();
                    } catch (Throwable $exception) {
                        if ($allOrNothing) {
                            throw $exception;
                        }

                        $errors[] = new ImportRowError($line, [$exception->getMessage()]);
                    }
                }
            });
        } catch (Throwable $exception) {
            return new StudentImportReport(
                $batchId,
                count($rows),
                0,
                [...$errors, new ImportRowError(0, [$exception->getMessage()])],
                true,
            );
        }

        return new StudentImportReport(
            batchId: $batchId,
            totalRows: count($rows),
            imported: count($created),
            errors: $errors,
            rolledBack: false,
            studentIds: $created,
        );
    }

    /**
     * Undo a completed import. Soft deletes, so anything already attached to a
     * student — answers, purchases, tickets — keeps its referent.
     *
     * @return int Number of students withdrawn.
     */
    public function rollback(string $batchId): int
    {
        $studentIds = StudentAcquisition::query()
            ->where('payload->import_batch_id', $batchId)
            ->pluck('student_id');

        $students = Student::query()->whereIn('id', $studentIds)->get();

        foreach ($students as $student) {
            $student->delete();
        }

        return $students->count();
    }

    /**
     * The panel hands over a local upload; the API job hands over the path it
     * stored on the tenant disk. Both must work, so a disk object is pulled
     * down to a temporary file the fgetcsv loop can stream.
     *
     * @return array{0: string|null, 1: bool} local path (null if unreadable), and whether it is ours to unlink
     */
    private function localise(string $path): array
    {
        if (is_file($path) && is_readable($path)) {
            return [$path, false];
        }

        try {
            $disk = Storage::disk('tenant');

            if (! $disk->exists($path)) {
                return [null, false];
            }

            $temp = tempnam(sys_get_temp_dir(), 'pte-student-import');
            $stream = $disk->readStream($path);

            if ($temp === false || ! is_resource($stream)) {
                return [null, false];
            }

            file_put_contents($temp, $stream);
            fclose($stream);

            return [$temp, true];
        } catch (Throwable) {
            return [null, false];
        }
    }

    /**
     * @return array<int, array<string, string>> Keyed by 1-based file line number.
     */
    private function readRows(string $path, ?string $delimiter): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $delimiter ??= str_ends_with(strtolower($path), '.tsv') ? "\t" : ',';
        $rows = [];
        $header = null;
        $line = 0;

        try {
            while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                if ($record === [null] || $record === []) {
                    continue;
                }

                if ($header === null) {
                    // Strip a UTF-8 BOM so the first column name still matches.
                    $record[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $record[0]);
                    $header = array_map(
                        static fn (mixed $column): string => Str::snake(trim(mb_strtolower((string) $column))),
                        $record
                    );

                    continue;
                }

                if (count(array_filter($record, static fn (mixed $v): bool => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $padded = array_pad(array_slice($record, 0, count($header)), count($header), '');
                $rows[$line] = array_combine($header, array_map(static fn (mixed $v): string => trim((string) $v), $padded));
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<int, string>  $seenCodes
     * @return array<int, string>
     */
    private function validateRow(array $row, array &$seenCodes): array
    {
        $messages = [];

        if (($row['first_name'] ?? '') === '') {
            $messages[] = 'Column [first_name] is required.';
        }

        $email = $row['email'] ?? '';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $messages[] = sprintf('Column [email] is not a valid address: [%s].', $email);
        }

        $targetScore = $row['target_score'] ?? '';

        if ($targetScore !== '' && ! is_numeric($targetScore)) {
            $messages[] = sprintf('Column [target_score] must be a number, got [%s].', $targetScore);
        }

        $status = $row['status'] ?? '';

        if ($status !== '' && StudentStatus::tryFrom(strtolower($status)) === null) {
            $messages[] = sprintf(
                'Unknown status [%s]. Expected one of: %s.',
                $status,
                implode(', ', array_map(static fn (StudentStatus $s): string => $s->value, StudentStatus::cases())),
            );
        }

        $source = $row['source'] ?? '';

        if ($source !== '' && StudentSource::tryFrom(strtolower($source)) === null) {
            $messages[] = sprintf(
                'Unknown source [%s]. Expected one of: %s.',
                $source,
                implode(', ', array_map(static fn (StudentSource $s): string => $s->value, StudentSource::cases())),
            );
        }

        $code = $row['student_code'] ?? '';

        if ($code !== '') {
            if (in_array($code, $seenCodes, true)) {
                $messages[] = sprintf('Duplicate student_code [%s] earlier in this file.', $code);
            } elseif (Student::query()->withTrashed()->where('student_code', $code)->exists()) {
                $messages[] = sprintf('student_code [%s] is already taken in this academy.', $code);
            }

            $seenCodes[] = $code;
        }

        foreach (array_keys($row) as $column) {
            if (! in_array($column, self::COLUMNS, true)) {
                $messages[] = sprintf('Unknown column [%s].', $column);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>  $options
     */
    private function toStudentData(array $row, array $options, string $batchId, int $line): CreateStudentData
    {
        $classGroupId = ($row['class_group_id'] ?? '') !== ''
            ? (int) $row['class_group_id']
            : ($options['class_group_id'] ?? null);

        $source = $options['source'] ?? StudentSource::Import;

        $attributes = array_filter($row, static fn (string $value): bool => $value !== '');

        // validateRow accepted these case-insensitively; fromArray matches
        // enum values exactly, so normalise before hydration.
        if (isset($attributes['status'])) {
            $attributes['status'] = strtolower($attributes['status']);
        }

        return CreateStudentData::fromArray([
            ...$attributes,
            'source' => isset($attributes['source']) ? strtolower($attributes['source']) : $source,
            'class_group_id' => $classGroupId,
            'payload' => ['import_batch_id' => $batchId, 'line' => $line],
        ]);
    }
}
