<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Services;

use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Models\Score;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds the data behind the report card — the bot message, the panel screen and
 * the PDF all render this same array.
 *
 * Rendering deliberately stays out: the moment this returned HTML or an image,
 * the Telegram layer and the panel would start disagreeing about what a report
 * card contains.
 *
 * @see docs/05-modules-exams-practice.md §5
 */
final class ReportCardBuilder
{
    /** A type a student is reliably good at, and one they are not. */
    private const STRENGTH_THRESHOLD = 75.0;

    private const WEAKNESS_THRESHOLD = 55.0;

    private const HIGHLIGHT_LIMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public function build(PracticeSession|ExamSession $session): array
    {
        return $session instanceof ExamSession
            ? $this->forExamSession($session)
            : $this->forPracticeSession($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function forExamSession(ExamSession $session): array
    {
        $answers = $this->answers($session);
        $byType = $this->byType($answers);
        $score = $this->summary($session);

        $total = (float) ($session->total_score ?? 0.0);
        $max = $session->maxScore() > 0.0 ? $session->maxScore() : $this->sumMax($answers);

        return [
            'kind' => 'exam',
            'session' => [
                'id' => (int) $session->getKey(),
                'type' => $session->sessionType()->value,
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                'attempt_number' => $session->attempt_number,
                'started_at' => $session->started_at?->toIso8601String(),
                'submitted_at' => $session->submitted_at?->toIso8601String(),
                'scored_at' => $session->scored_at?->toIso8601String(),
            ],
            'student' => $this->student($session),
            'exam' => [
                'id' => (int) $session->exam_id,
                'title' => (string) data_get($session->snapshot ?? [], 'exam.title', ''),
            ],
            'total' => [
                'score' => round($total, 2),
                'max' => round($max, 2),
                'percentage' => $max > 0.0 ? round($total / $max * 100, 2) : 0.0,
                'passing_score' => $session->passingScore(),
                'passed' => $session->passed,
            ],
            'sections' => $this->sections($session),
            'by_type' => $byType,
            'strengths' => $this->strengths($byType),
            'weaknesses' => $this->weaknesses($byType),
            'answers' => $this->answerRows($answers),
            'pending' => $this->pending($answers),
            'published' => $score?->isPublished() ?? false,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forPracticeSession(PracticeSession $session): array
    {
        $answers = $this->answers($session);
        $byType = $this->byType($answers);

        $total = (float) ($session->total_score ?? 0.0);
        $max = (float) ($session->max_score ?? $this->sumMax($answers));

        return [
            'kind' => 'practice',
            'session' => [
                'id' => (int) $session->getKey(),
                'type' => $session->sessionType()->value,
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                'module_key' => $session->module_key?->value,
                'question_type' => $session->question_type?->value,
                'started_at' => $session->started_at?->toIso8601String(),
                'completed_at' => $session->completed_at?->toIso8601String(),
                'duration_seconds' => $session->duration_seconds,
            ],
            'student' => $this->student($session),
            'total' => [
                'score' => round($total, 2),
                'max' => round($max, 2),
                'percentage' => $max > 0.0 ? round($total / $max * 100, 2) : 0.0,
                'answered' => $session->answered,
                'total_questions' => $session->total_questions,
            ],
            'sections' => [],
            'by_type' => $byType,
            'strengths' => $this->strengths($byType),
            'weaknesses' => $this->weaknesses($byType),
            'answers' => $this->answerRows($answers),
            'pending' => $this->pending($answers),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, Answer>
     */
    private function answers(PracticeSession|ExamSession $session): Collection
    {
        return Answer::query()
            ->forSession($session->sessionType(), (int) $session->getKey())
            ->orderBy('id')
            ->get();
    }

    private function summary(PracticeSession|ExamSession $session): ?Score
    {
        return Score::query()
            ->forSession($session->sessionType(), (int) $session->getKey())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function student(PracticeSession|ExamSession $session): array
    {
        $student = null;

        try {
            $student = $session->student;
        } catch (Throwable) {
            // Identity context may be absent in a report generated offline.
        }

        return [
            'id' => (int) $session->student_id,
            'name' => $student instanceof Model ? $this->nameOf($student) : '',
        ];
    }

    private function nameOf(Model $student): string
    {
        if (method_exists($student, 'fullName')) {
            return (string) $student->fullName();
        }

        return (string) ($student->getAttribute('full_name') ?? $student->getAttribute('name') ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sections(ExamSession $session): array
    {
        $scores = collect($session->section_scores ?? [])->keyBy(
            static fn (mixed $row): int => (int) (is_array($row) ? ($row['id'] ?? 0) : 0)
        );

        return array_map(static function (array $section) use ($scores): array {
            $row = $scores->get((int) ($section['id'] ?? 0));
            $score = (float) (is_array($row) ? ($row['score'] ?? 0.0) : 0.0);
            $max = (float) (is_array($row) ? ($row['max'] ?? 0.0) : (float) ($section['score'] ?? 0.0));

            return [
                'id' => (int) ($section['id'] ?? 0),
                'title' => (string) ($section['title'] ?? ''),
                'module_key' => $section['module_key'] ?? null,
                'score' => round($score, 2),
                'max' => round($max, 2),
                'percentage' => $max > 0.0 ? round($score / $max * 100, 2) : 0.0,
                'question_count' => count((array) ($section['questions'] ?? [])),
            ];
        }, $session->sectionSnapshots());
    }

    /**
     * @param  Collection<int, Answer>  $answers
     * @return array<int, array<string, mixed>>
     */
    private function byType(Collection $answers): array
    {
        $buckets = [];

        foreach ($answers as $answer) {
            $type = $this->typeOf($answer);

            if (! $type instanceof QuestionType) {
                continue;
            }

            $bucket = $buckets[$type->value] ?? [
                'type' => $type->value,
                'label' => $type->label(),
                'module_key' => $type->module()->value,
                'score' => 0.0,
                'max' => 0.0,
                'count' => 0,
                'percentage' => 0.0,
            ];

            $bucket['score'] += (float) ($answer->score ?? 0.0);
            $bucket['max'] += (float) ($answer->max_score ?? 0.0);
            $bucket['count']++;

            $buckets[$type->value] = $bucket;
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['score'] = round($bucket['score'], 2);
            $buckets[$key]['max'] = round($bucket['max'], 2);
            $buckets[$key]['percentage'] = $bucket['max'] > 0.0
                ? round($bucket['score'] / $bucket['max'] * 100, 2)
                : 0.0;
        }

        return array_values($buckets);
    }

    /**
     * @param  array<int, array<string, mixed>>  $byType
     * @return array<int, array<string, mixed>>
     */
    private function strengths(array $byType): array
    {
        $strong = array_values(array_filter(
            $byType,
            static fn (array $row): bool => (float) $row['percentage'] >= self::STRENGTH_THRESHOLD
        ));

        usort($strong, static fn (array $a, array $b): int => $b['percentage'] <=> $a['percentage']);

        return array_slice($strong, 0, self::HIGHLIGHT_LIMIT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $byType
     * @return array<int, array<string, mixed>>
     */
    private function weaknesses(array $byType): array
    {
        $weak = array_values(array_filter(
            $byType,
            static fn (array $row): bool => (float) $row['percentage'] <= self::WEAKNESS_THRESHOLD && (int) $row['count'] > 0
        ));

        usort($weak, static fn (array $a, array $b): int => $a['percentage'] <=> $b['percentage']);

        return array_slice($weak, 0, self::HIGHLIGHT_LIMIT);
    }

    /**
     * @param  Collection<int, Answer>  $answers
     * @return array<int, array<string, mixed>>
     */
    private function answerRows(Collection $answers): array
    {
        return $answers->map(function (Answer $answer): array {
            $type = $this->typeOf($answer);

            return [
                'answer_id' => (int) $answer->getKey(),
                'question_id' => (int) $answer->question_id,
                'type' => $type?->value,
                'type_label' => $type?->label(),
                'score' => $answer->score === null ? null : round((float) $answer->score, 2),
                'max' => $answer->max_score === null ? null : round((float) $answer->max_score, 2),
                'percentage' => $answer->percentage(),
                'scoring_status' => $answer->scoring_status->value,
                'scored_by' => $answer->scored_by?->value,
                'graded_manually' => (bool) $answer->graded_manually,
                'feedback' => $answer->feedback ?? [],
                'breakdown' => $answer->breakdown ?? [],
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Answer>  $answers
     */
    private function pending(Collection $answers): int
    {
        return $answers->filter(fn (Answer $answer): bool => $answer->scoring_status->isPending())->count();
    }

    /**
     * @param  Collection<int, Answer>  $answers
     */
    private function sumMax(Collection $answers): float
    {
        return round((float) $answers->sum(fn (Answer $a): float => (float) ($a->max_score ?? 0.0)), 2);
    }

    private function typeOf(Answer $answer): ?QuestionType
    {
        $stored = $answer->payloadValue('question_type');

        if (is_string($stored) && ($type = QuestionType::tryFrom($stored)) instanceof QuestionType) {
            return $type;
        }

        try {
            return $answer->questionType();
        } catch (Throwable) {
            return null;
        }
    }
}
