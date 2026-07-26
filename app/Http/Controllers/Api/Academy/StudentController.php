<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Identity\Actions\CreateStudent;
use App\Domain\Identity\Data\CreateStudentData;
use App\Domain\Identity\Enums\StudentStatus;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportType;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\StoreStudentRequest;
use App\Http\Requests\Api\Academy\UpdateStudentRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\StudentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/students` — docs/08 §3.
 *
 * Every query here is tenant-scoped by the global scope, which is also why a
 * foreign id produces 404 rather than 403: the row simply does not exist from
 * this key's point of view (docs/12 §1, T10).
 */
final class StudentController extends ApiController
{
    public function index(Request $request, ExportReport $export): ApiCollection|JsonResponse
    {
        $request->validate(['format' => ['nullable', 'in:json,xlsx']]);

        if ($request->string('format')->toString() === 'xlsx') {
            return $this->queueExport($request, $export);
        }

        $students = Student::query()
            ->when(
                $request->filled('status'),
                fn (Builder $query): Builder => $query->where('status', (string) $request->string('status'))
            )
            ->when(
                $request->filled('level'),
                fn (Builder $query): Builder => $query->where('level', (string) $request->string('level'))
            )
            ->when($request->filled('search'), function (Builder $query) use ($request): Builder {
                $term = '%'.$request->string('search')->toString().'%';

                return $query->where(fn (Builder $inner): Builder => $inner
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('student_code', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($students, StudentResource::class);
    }

    public function store(StoreStudentRequest $request, CreateStudent $action): JsonResponse
    {
        $student = $action->handle(CreateStudentData::fromArray($request->validated()));

        return StudentResource::make($student)
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $student): StudentResource
    {
        return StudentResource::make(Student::query()->findOrFail($student));
    }

    public function update(UpdateStudentRequest $request, int $student): StudentResource
    {
        $model = Student::query()->findOrFail($student);

        $model->fill($request->validated())->save();

        return StudentResource::make($model->refresh());
    }

    /**
     * Soft delete: docs/12 §4 gives the student a right to erasure within 30
     * days, and the scheduler — not an integration call — is what enacts it.
     */
    public function destroy(int $student): JsonResponse
    {
        $model = Student::query()->findOrFail($student);

        $model->forceFill(['status' => StudentStatus::Inactive])->save();
        $model->delete();

        return new JsonResponse(null, 204);
    }

    /** `?format=xlsx` — the roster as a report build, 202 + id to poll at `/exports/{id}`. */
    private function queueExport(Request $request, ExportReport $export): JsonResponse
    {
        $params = array_filter([
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
        ], static fn (?string $value): bool => $value !== null);

        $report = $export->queue(ReportType::Students, $params, format: 'xlsx');

        return $this->payload($request, [
            'report_id' => (int) $report->getKey(),
            'status' => $report->status->value,
            'format' => 'xlsx',
        ], status: 202);
    }
}
