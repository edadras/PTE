<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academy;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\Score;
use App\Domain\Reporting\Actions\ExportReport;
use App\Domain\Reporting\Enums\ReportType;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/exams/{id}/results?format=json|xlsx` — docs/08 §3.
 *
 * `json` is served inline. A spreadsheet of a whole cohort is a report build,
 * so `xlsx` registers a report on the reports queue and answers 202 with the
 * id to poll at `GET /api/v1/exports/{id}` for the signed download link.
 */
final class ExamResultController extends ApiController
{
    public function __invoke(Request $request, int $exam, ExportReport $export): JsonResponse
    {
        $model = Exam::query()->findOrFail($exam);

        $request->validate(['format' => ['nullable', 'in:json,xlsx']]);

        if ($request->string('format')->toString() === 'xlsx') {
            $report = $export->queue(
                ReportType::ExamResults,
                ['exam_id' => (int) $model->getKey()],
                format: 'xlsx',
            );

            return $this->payload($request, [
                'report_id' => (int) $report->getKey(),
                'status' => $report->status->value,
                'format' => 'xlsx',
            ], status: 202);
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
