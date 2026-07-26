<?php

declare(strict_types=1);

namespace App\Domain\Integration\Jobs;

use App\Domain\Identity\Actions\CreateStudent;
use App\Domain\Identity\Actions\ImportStudents;
use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Domain\Shared\Support\TenantKey;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Runs a student CSV import off the request cycle — the async half of
 * `POST /api/v1/students/import` (docs/08 §3).
 *
 * Progress and the final per-row error report are parked in the tenant cache
 * under the import id, exactly as ImportQuestionsJob does for question banks,
 * so `GET /api/v1/students/import/{id}` can poll the same way the panel does.
 *
 * Delegates to Identity's ImportStudents action when it exists; until that
 * lands, a row-by-row CreateStudent loop keeps the endpoint honest rather than
 * queueing work nothing would perform.
 */
final class ImportStudentsJob extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 900;

    private const CACHE_HOURS = 6;

    /** @var array<string, array<int, string>> */
    private const ROW_RULES = [
        'first_name' => ['required', 'string', 'max:80'],
        'last_name' => ['nullable', 'string', 'max:80'],
        'email' => ['nullable', 'email', 'max:191'],
        'phone' => ['nullable', 'string', 'max:32'],
        'level' => ['nullable', 'string', 'max:10'],
        'student_code' => ['nullable', 'string', 'max:32'],
    ];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        int $academyId,
        public readonly string $path,
        public readonly string $importId,
        public readonly array $options = [],
    ) {
        parent::__construct($academyId);
    }

    public function handle(CreateStudent $createStudent): void
    {
        $this->publish(['status' => 'running', 'total_rows' => 0, 'imported' => 0, 'failed' => 0, 'errors' => []]);

        $this->publish(['status' => 'completed', ...$this->runImport($createStudent)]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->publish([
            'status' => 'failed',
            'errors' => [['line' => 0, 'messages' => [$exception?->getMessage() ?? 'Unknown error']]],
        ]);
    }

    public static function progressKeyFor(int $academyId, string $importId): string
    {
        return TenantKey::for($academyId, 'student-import', $importId);
    }

    /** Written before dispatch so a poll that races the worker sees "queued", not 404. */
    public static function markQueued(int $academyId, string $importId): void
    {
        Cache::put(
            self::progressKeyFor($academyId, $importId),
            ['status' => 'queued', 'total_rows' => 0, 'imported' => 0, 'failed' => 0, 'errors' => []],
            now()->addHours(self::CACHE_HOURS),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function statusFor(int $academyId, string $importId): ?array
    {
        $payload = Cache::get(self::progressKeyFor($academyId, $importId));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [...parent::tags(), 'student-import:'.$this->importId];
    }

    /**
     * @return array<string, mixed>
     */
    private function runImport(CreateStudent $createStudent): array
    {
        // Built in parallel by the Identity context; preferred as soon as it
        // exists so panel and API imports share one implementation.
        if (class_exists(ImportStudents::class)) {
            $report = app(ImportStudents::class)->handle($this->path, [
                ...$this->options,
                'import_id' => $this->importId,
            ]);

            return $this->normalise(
                is_object($report) && method_exists($report, 'toArray') ? (array) $report->toArray() : [],
            );
        }

        return $this->importRows($createStudent);
    }

    /**
     * Different report DTOs spell the same facts differently; the polling
     * endpoint promises one stable contract, so key variants collapse here.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function normalise(array $report): array
    {
        $errors = is_array($report['errors'] ?? null) ? array_values($report['errors']) : [];

        return [
            'total_rows' => (int) ($report['total_rows'] ?? $report['total'] ?? 0),
            'imported' => (int) ($report['imported'] ?? $report['created'] ?? 0),
            'failed' => (int) ($report['failed'] ?? count($errors)),
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function importRows(CreateStudent $createStudent): array
    {
        $total = 0;
        $imported = 0;
        $errors = [];

        foreach ($this->rows() as $line => $row) {
            $total++;

            $validator = Validator::make($row, self::ROW_RULES);

            if ($validator->fails()) {
                $errors[] = ['line' => $line, 'messages' => $validator->errors()->all()];

                continue;
            }

            try {
                $createStudent->handle(CreateStudentData::fromArray(array_filter(
                    $validator->validated(),
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                )));

                $imported++;
            } catch (Throwable $e) {
                $errors[] = ['line' => $line, 'messages' => [$e->getMessage()]];
            }
        }

        return [
            'total_rows' => $total,
            'imported' => $imported,
            'failed' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @return Generator<int, array<string, string|null>> line number => row
     */
    private function rows(): Generator
    {
        $contents = Storage::disk('tenant')->get($this->path);

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException(__('api.errors.import_file_unreadable'));
        }

        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $header = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map('trim', str_getcsv($line, ',', '"', '\\'));

            if ($header === null) {
                $header = array_map(
                    static fn (string $cell): string => str_replace(' ', '_', mb_strtolower($cell)),
                    $cells,
                );

                if (! in_array('first_name', $header, true)) {
                    throw new RuntimeException(__('api.errors.import_header_invalid'));
                }

                continue;
            }

            $row = [];

            foreach ($header as $position => $column) {
                if (array_key_exists($column, self::ROW_RULES)) {
                    $row[$column] = $cells[$position] ?? null;
                }
            }

            yield $index + 1 => $row;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(array $payload): void
    {
        Cache::put(
            self::progressKeyFor($this->academyId, $this->importId),
            ['import_id' => $this->importId, ...$payload],
            now()->addHours(self::CACHE_HOURS),
        );
    }
}
