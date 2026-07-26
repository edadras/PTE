<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\Score;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/exams/{id}/results?format=json|xlsx` — docs/08 §3.
 *
 * Only `json` is served inline. An xlsx of a whole cohort is a report build,
 * which belongs on the reports queue, not in a request/response cycle.
 */
final class ExamResultController extends ApiController
{
    public function __invoke(Request $request, int $exam): JsonResponse
    {
        $model = Exam::query()->findOrFail($exam);

        $request->validate(['format' => ['nullable', 'in:json,xlsx']]);

        if ($request->string('format')->toString() === 'xlsx') {
            return $this->payload($request, [
                'status' => 'unsupported',
                'message' => __('api.errors.export_async_only'),
            ], status: 501);
        }

        $sessionIds = ExamSession::query()
            ->where('exam_id', $model->getKey())
            ->pluck('id');

        $scores = Score::query()
            ->where('session_type', SessionType::Exam->value)
            ->whereIn('session_id', $sessionIds)
            ->get()
            ->map(static fn (Score $score): array => [
                'session_id' => (int) $score->session_id,
                'student_id' => (int) $score->student_id,
                'raw_score' => (float) $score->raw_score,
                'scaled_score' => $score->scaled_score === null ? null : (float) $score->scaled_score,
                'percentage' => (float) $score->percentage,
                'published_at' => $score->published_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $this->payload($request, [
            'exam_id' => (int) $model->getKey(),
            'sessions' => $sessionIds->count(),
            'results' => $scores,
        ]);
    }
}
