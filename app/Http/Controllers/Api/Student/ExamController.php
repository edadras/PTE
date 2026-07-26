<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Assessment\Actions\StartExamSession;
use App\Domain\Assessment\Actions\SubmitExamAnswer;
use App\Domain\Assessment\Actions\SubmitExamSession;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\SubmitAnswerRequest;
use App\Http\Resources\AnswerResource;
use App\Http\Resources\ApiCollection;
use App\Http\Resources\ExamResource;
use App\Http\Resources\ExamSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/api/student/v1/exams` and `/exam-sessions` — docs/08 §3.
 */
final class ExamController extends ApiController
{
    public function index(Request $request): ApiCollection
    {
        $exams = Exam::query()
            ->published()
            ->orderByDesc('published_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return new ApiCollection($exams, ExamResource::class);
    }

    public function start(Request $request, int $exam, StartExamSession $action): JsonResponse
    {
        $model = Exam::query()->published()->findOrFail($exam);

        $session = $action->handle($model, $this->student($request));

        return ExamSessionResource::make($session)->response()->setStatusCode(201);
    }

    public function session(Request $request, int $session): ExamSessionResource
    {
        return ExamSessionResource::make($this->ownedSession($request, $session));
    }

    public function answer(SubmitAnswerRequest $request, int $session, SubmitExamAnswer $action): JsonResponse
    {
        $model = $this->ownedSession($request, $session);
        $validated = $request->validated();

        $answer = $action->handle(
            session: $model,
            questionId: (int) $validated['question_id'],
            answerData: (array) ($validated['answer'] ?? []),
            mediaPath: $validated['media_path'] ?? null,
            transcript: $validated['transcript'] ?? null,
            skipped: (bool) ($validated['skipped'] ?? false),
        );

        return AnswerResource::make($answer)->response()->setStatusCode(201);
    }

    public function submit(Request $request, int $session, SubmitExamSession $action): ExamSessionResource
    {
        return ExamSessionResource::make($action->handle($this->ownedSession($request, $session)));
    }

    private function ownedSession(Request $request, int $session): ExamSession
    {
        $model = ExamSession::query()
            ->where('student_id', $this->student($request)->getKey())
            ->find($session);

        if (! $model instanceof ExamSession) {
            throw new NotFoundHttpException(__('api.errors.not_found'));
        }

        return $model;
    }
}
