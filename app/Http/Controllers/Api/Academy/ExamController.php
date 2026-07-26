<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Actions\PublishExam;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Academy\StoreExamRequest;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ExamResource;
use App\Http\Resources\ExamSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/exams` — docs/08 §3.
 */
final class ExamController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $exams = Exam::query()
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($exams, ExamResource::class);
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $exam = Exam::query()->create($request->validated());

        return ExamResource::make($exam)->response()->setStatusCode(201);
    }

    public function publish(int $exam, PublishExam $action): ExamResource
    {
        return ExamResource::make($action->handle(Exam::query()->findOrFail($exam)));
    }

    public function sessions(Request $request, int $exam): ApiCollection
    {
        $model = Exam::query()->findOrFail($exam);

        $sessions = ExamSession::query()
            ->where('exam_id', $model->getKey())
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($sessions, ExamSessionResource::class);
    }
}
