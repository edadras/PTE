<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Identity\Actions\CreateStudent;
use App\Domain\Identity\Data\CreateStudentData;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * `POST /api/v1/students/import` — docs/08 §3.
 *
 * The Identity context has no bulk-import job yet, so this endpoint takes an
 * inline JSON array rather than pretending a file upload is queued. A capped,
 * synchronous batch is honest about what happens; a fake 202 would not be.
 * When Identity grows an ImportStudents action this controller should hand the
 * uploaded file to it instead.
 */
final class StudentImportController extends ApiController
{
    private const MAX_ROWS = 500;

    public function __invoke(Request $request, CreateStudent $action): JsonResponse
    {
        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'students.*.first_name' => ['required', 'string', 'max:80'],
            'students.*.last_name' => ['nullable', 'string', 'max:80'],
            'students.*.email' => ['nullable', 'email', 'max:191'],
            'students.*.phone' => ['nullable', 'string', 'max:32'],
            'students.*.level' => ['nullable', 'string', 'max:10'],
            'students.*.student_code' => ['nullable', 'string', 'max:32'],
        ]);

        $created = [];
        $failed = [];

        foreach ($validated['students'] as $index => $row) {
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
