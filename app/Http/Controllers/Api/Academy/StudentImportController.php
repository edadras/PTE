<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Identity\Actions\CreateStudent;
use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Integration\Jobs\ImportStudentsJob;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * `POST /api/v1/students/import` — docs/08 §3 (CSV/Excel → job).
 *
 * A CSV upload is stored on the tenant disk and handed to a queued job, 202 +
 * import id, mirroring the questions bulk-import endpoint. The inline JSON
 * array is kept as a documented small-batch convenience: it is capped, runs
 * synchronously and answers 201 with the ids, which an integration pushing a
 * handful of enrolments genuinely wants.
 *
 * `GET /api/v1/students/import/{id}` polls the job's progress and, once done,
 * the per-row error report.
 */
final class StudentImportController extends ApiController
{
    private const MAX_INLINE_ROWS = 500;

    public function store(Request $request, CreateStudent $action): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required_without:students', 'prohibits:students', 'file', 'mimes:csv,txt', 'max:10240'],
            'students' => ['required_without:file', 'array', 'min:1', 'max:'.self::MAX_INLINE_ROWS],
            'students.*.first_name' => ['required', 'string', 'max:80'],
            'students.*.last_name' => ['nullable', 'string', 'max:80'],
            'students.*.email' => ['nullable', 'email', 'max:191'],
            'students.*.phone' => ['nullable', 'string', 'max:32'],
            'students.*.level' => ['nullable', 'string', 'max:10'],
            'students.*.student_code' => ['nullable', 'string', 'max:32'],
        ]);

        if ($request->hasFile('file')) {
            return $this->queueFile($request);
        }

        return $this->importInline($request, $validated['students'], $action);
    }

    public function show(Request $request, string $importId): JsonResponse
    {
        $status = ImportStudentsJob::statusFor(TenantContext::id(), $importId);

        if ($status === null) {
            throw new NotFoundHttpException(__('api.errors.not_found'));
        }

        return $this->payload($request, ['import_id' => $importId, ...$status]);
    }

    private function queueFile(Request $request): JsonResponse
    {
        $path = (string) $request->file('file')?->store('imports/students', 'tenant');
        $importId = (string) Str::uuid();
        $academyId = TenantContext::id();

        ImportStudentsJob::markQueued($academyId, $importId);
        ImportStudentsJob::dispatch($academyId, $path, $importId);

        return $this->payload($request, [
            'import_id' => $importId,
            'status' => 'queued',
        ], status: 202);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function importInline(Request $request, array $rows, CreateStudent $action): JsonResponse
    {
        $created = [];
        $failed = [];

        foreach ($rows as $index => $row) {
            try {
                $student = $action->handle(CreateStudentData::fromArray($row));
                $created[] = (int) $student->getKey();
            } catch (Throwable $e) {
                $failed[] = ['row' => (int) $index, 'message' => $e->getMessage()];
            }
        }

        return $this->payload($request, [
            'created' => count($created),
            'failed' => count($failed),
            'student_ids' => $created,
            'errors' => $failed,
        ], status: 201);
    }
}
