<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Student;

use App\Domain\Assessment\Actions\FinishPracticeSession;
use App\Domain\Assessment\Actions\StartPracticeSession;
use App\Domain\Assessment\Actions\SubmitAnswer;
use App\Domain\Assessment\Data\SubmitAnswerData;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Learning\Enums\ModuleKey;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Student\StartPracticeRequest;
use App\Http\Requests\Api\Student\SubmitAnswerRequest;
use App\Http\Resources\AnswerResource;
use App\Http\Resources\PracticeSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/api/student/v1/practice/*` — docs/08 §3.
 *
 * A session is only ever reachable by the student who owns it. The lookup is
 * tenant-scoped *and* filtered by student id, and a miss is a 404: "this
 * session belongs to someone else" is information we do not give out.
 */
final class PracticeController extends ApiController
{
    public function start(StartPracticeRequest $request, StartPracticeSession $action): JsonResponse
    {
        $validated = $request->validated();

        $target = isset($validated['type'])
            ? QuestionType::from((string) $validated['type'])
            : ModuleKey::from((string) $validated['module']);

        $session = $action->handle(
            $this->student($request),
            $target,
            (int) ($validated['count'] ?? 0),
        );

        return PracticeSessionResource::make($session)->response()->setStatusCode(201);
    }

    public function show(Request $request, int $session): PracticeSessionResource
    {
        $model = $this->ownedSession($request, $session);

        // The drawn set is a JSON column rather than a relation, so it is
        // hydrated explicitly and handed to the resource the same way
        // StartPracticeSession hands it back on creation.
        $model->setRelation('questions', Question::query()
            ->with(['options', 'media'])
            ->whereIn('id', $model->question_ids ?? [])
            ->get());

        return PracticeSessionResource::make($model);
    }

    public function answer(SubmitAnswerRequest $request, int $session, SubmitAnswer $action): JsonResponse
    {
        $model = $this->ownedSession($request, $session);
        $validated = $request->validated();

        $answer = $action->handle(SubmitAnswerData::forPractice(
            sessionId: (int) $model->getKey(),
            questionId: (int) $validated['question_id'],
            studentId: (int) $model->student_id,
            answerData: (array) ($validated['answer'] ?? []),
            mediaPath: $validated['media_path'] ?? null,
            transcript: $validated['transcript'] ?? null,
            skipped: (bool) ($validated['skipped'] ?? false),
        ));

        return AnswerResource::make($answer)->response()->setStatusCode(201);
    }

    public function finish(Request $request, int $session, FinishPracticeSession $action): PracticeSessionResource
    {
        return PracticeSessionResource::make(
            $action->handle($this->ownedSession($request, $session))
        );
    }

    private function ownedSession(Request $request, int $session): PracticeSession
    {
        $model = PracticeSession::query()
            ->where('student_id', $this->student($request)->getKey())
            ->find($session);

        if (! $model instanceof PracticeSession) {
            throw new NotFoundHttpException(__('api.errors.not_found'));
        }

        return $model;
    }
}
