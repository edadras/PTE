<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Identity\Models\Student;
use App\Domain\Reporting\Enums\ReportType;
use Generator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns a report type plus filters into a header row and a lazy row generator.
 *
 * Rows are yielded through chunkById rather than collected: an academy with
 * 50k answers must not need 50k hydrated models resident at once for an export
 * that is written straight to disk.
 *
 * Runs inside the tenant — every query here is tenant-scoped.
 */
final class ReportDataSource
{
    private const CHUNK = 500;

    /**
     * @return array<int, string>
     */
    public function headers(ReportType $type): array
    {
        return match ($type) {
            ReportType::Students => [
                'id', 'student_code', 'first_name', 'last_name', 'phone', 'email',
                'status', 'source', 'target_score', 'registered_at', 'last_active_at',
            ],
            ReportType::Scores => [
                'answer_id', 'student_id', 'session_type', 'session_id', 'question_id',
                'question_type', 'score', 'max_score', 'percentage', 'scoring_status',
                'scored_by', 'graded_manually', 'submitted_at',
            ],
            ReportType::ExamResults => [
                'session_id', 'exam_id', 'student_id', 'attempt_number', 'status',
                'total_score', 'max_score', 'percentage', 'passed', 'started_at', 'submitted_at',
            ],
            ReportType::PracticeSessions => [
                'session_id', 'student_id', 'module_key', 'question_type', 'status',
                'total_questions', 'answered', 'total_score', 'max_score', 'percentage',
                'started_at', 'completed_at', 'duration_seconds',
            ],
            default => throw new InvalidArgumentException("Report type [{$type->value}] has no tabular form."),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<int, mixed>>
     */
    public function rows(ReportType $type, array $params = []): Generator
    {
        return match ($type) {
            ReportType::Students => $this->students($params),
            ReportType::Scores => $this->scores($params),
            ReportType::ExamResults => $this->examResults($params),
            ReportType::PracticeSessions => $this->practiceSessions($params),
            default => throw new InvalidArgumentException("Report type [{$type->value}] has no tabular form."),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<int, mixed>>
     */
    private function students(array $params): Generator
    {
        $query = Student::query()->orderBy('id');

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        [$from, $to] = $this->range($params);

        if ($from instanceof Carbon) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        foreach ($this->cursor($query) as $student) {
            yield [
                $student->getKey(),
                $student->student_code,
                $student->first_name,
                $student->last_name,
                // $hidden on the model protects serialisation, not this export;
                // an academy exporting its own roster is entitled to it, and the
                // export itself is a mandatory audit event (docs/02 §7).
                $student->getAttribute('phone'),
                $student->getAttribute('email'),
                $student->status,
                $student->source,
                $student->getAttribute('target_score'),
                $student->getAttribute('registered_at'),
                $student->getAttribute('last_active_at'),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<int, mixed>>
     */
    private function scores(array $params): Generator
    {
        $query = Answer::query()->orderBy('id');

        if (isset($params['student_id'])) {
            $query->where('student_id', (int) $params['student_id']);
        }

        if (isset($params['session_type'])) {
            $query->where('session_type', $params['session_type']);
        }

        [$from, $to] = $this->range($params);

        if ($from instanceof Carbon) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        foreach ($this->cursor($query) as $answer) {
            yield [
                $answer->getKey(),
                $answer->student_id,
                $answer->session_type,
                $answer->session_id,
                $answer->question_id,
                $answer->payloadValue('question_type'),
                $answer->score,
                $answer->max_score,
                $answer->percentage(),
                $answer->scoring_status,
                $answer->scored_by,
                $answer->graded_manually,
                $answer->getAttribute('created_at'),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<int, mixed>>
     */
    private function examResults(array $params): Generator
    {
        $query = ExamSession::query()->orderBy('id');

        if (isset($params['exam_id'])) {
            $query->where('exam_id', (int) $params['exam_id']);
        }

        [$from, $to] = $this->range($params);

        if ($from instanceof Carbon) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        foreach ($this->cursor($query) as $session) {
            $max = $session->maxScore();
            $total = (float) ($session->total_score ?? 0.0);

            yield [
                $session->getKey(),
                $session->exam_id,
                $session->student_id,
                $session->attempt_number,
                $session->status,
                $total,
                $max,
                $max > 0.0 ? round($total / $max * 100, 2) : 0.0,
                $session->passed,
                $session->started_at,
                $session->submitted_at,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<int, mixed>>
     */
    private function practiceSessions(array $params): Generator
    {
        $query = PracticeSession::query()->orderBy('id');

        if (isset($params['student_id'])) {
            $query->where('student_id', (int) $params['student_id']);
        }

        [$from, $to] = $this->range($params);

        if ($from instanceof Carbon) {
            $query->whereBetween('created_at', [$from, $to]);
        }

        foreach ($this->cursor($query) as $session) {
            yield [
                $session->getKey(),
                $session->student_id,
                $session->module_key,
                $session->question_type,
                $session->status,
                $session->total_questions,
                $session->answered,
                $session->total_score,
                $session->max_score,
                $session->percentage(),
                $session->started_at,
                $session->completed_at,
                $session->duration_seconds,
            ];
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @return Generator<int, TModel>
     */
    private function cursor($query): Generator
    {
        $lastId = 0;

        do {
            $chunk = (clone $query)
                ->where($query->getModel()->getQualifiedKeyName(), '>', $lastId)
                ->limit(self::CHUNK)
                ->get();

            foreach ($chunk as $model) {
                $lastId = (int) $model->getKey();

                yield $model;
            }
        } while ($chunk->count() === self::CHUNK);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function range(array $params): array
    {
        $from = isset($params['from']) ? Carbon::parse((string) $params['from'])->startOfDay() : null;
        $to = isset($params['to']) ? Carbon::parse((string) $params['to'])->endOfDay() : null;

        if ($from instanceof Carbon && ! $to instanceof Carbon) {
            $to = now();
        }

        return [$from, $to];
    }
}
