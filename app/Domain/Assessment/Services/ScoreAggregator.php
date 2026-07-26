<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Services;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Events\SessionScored;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Models\Score;
use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Turns a session's answers into the one summary row everything else reads.
 *
 * Recomputing a report card from `answers` on every dashboard load is the query
 * that falls over first at scale, so the aggregate is written once when the
 * session closes — and rewritten in place if a teacher later overrides a grade.
 */
final class ScoreAggregator
{
    public function aggregate(PracticeSession|ExamSession $session): Score
    {
        return $session instanceof ExamSession
            ? $this->aggregateExam($session)
            : $this->aggregatePractice($session);
    }

    /** True while the AI queue still owes this session a grade. */
    public function hasPendingAnswers(PracticeSession|ExamSession $session): bool
    {
        return $this->answers($session)
            ->contains(fn (Answer $answer): bool => $answer->scoring_status->isPending());
    }

    private function aggregatePractice(PracticeSession $session): Score
    {
        $answers = $this->answers($session);
        $totals = $this->totals($answers);

        $session->forceFill([
            'total_score' => $totals['score'],
            'max_score' => $totals['max'],
            'answered' => $answers->count(),
        ])->save();

        $score = $this->writeScore(
            $session,
            SessionType::Practice,
            $totals,
            [
                'by_type' => $this->byType($answers),
                'answered' => $answers->count(),
                'total_questions' => $session->total_questions,
                'pending' => $this->pendingCount($answers),
            ],
            $session->module_key?->value,
            publishedAt: now()->toDateTimeString(),
        );

        SessionScored::dispatch($score, $session);

        return $score;
    }

    private function aggregateExam(ExamSession $session): Score
    {
        $wasExpired = $session->status === SessionStatus::Expired;
        $answers = $this->answers($session);
        $totals = $this->totals($answers);

        $sections = $this->bySection($session, $answers);
        $passing = $session->passingScore();

        // The paper's declared total, not the sum of what was answered: skipping
        // half an exam must not produce a 100%.
        $max = $session->maxScore() > 0.0 ? $session->maxScore() : $totals['max'];

        $session->forceFill([
            'total_score' => $totals['score'],
            'section_scores' => $sections,
            'passed' => $passing === null ? null : $totals['score'] >= $passing,
            'status' => SessionStatus::Scored,
            'scored_at' => now(),
        ])->save();

        $requiresApproval = (bool) data_get($session->snapshot ?? [], 'exam.rules.require_teacher_approval', false);

        // Scoring overwrites the Expired status, so the fact that the clock —
        // not the student — ended the attempt is preserved here instead.
        $autoSubmitted = $wasExpired;

        $score = $this->writeScore(
            $session,
            SessionType::Exam,
            ['score' => $totals['score'], 'max' => $max],
            [
                'by_section' => $sections,
                'by_type' => $this->byType($answers),
                'answered' => $answers->count(),
                'total_questions' => $session->totalQuestions(),
                'pending' => $this->pendingCount($answers),
                'passing_score' => $passing,
                'passed' => $session->passed,
                'auto_submitted' => $autoSubmitted,
            ],
            null,
            // Academies that gate grades behind a teacher get an unpublished row;
            // the record exists, the student just cannot see it yet.
            publishedAt: $requiresApproval ? null : now()->toDateTimeString(),
        );

        SessionScored::dispatch($score, $session);

        return $score;
    }

    /**
     * @param  array{score: float, max: float}  $totals
     * @param  array<string, mixed>  $breakdown
     */
    private function writeScore(
        PracticeSession|ExamSession $session,
        SessionType $type,
        array $totals,
        array $breakdown,
        ?string $moduleKey,
        ?string $publishedAt,
    ): Score {
        $percentage = $totals['max'] > 0.0 ? round($totals['score'] / $totals['max'] * 100, 2) : 0.0;

        /** @var Score $score */
        $score = Score::query()->updateOrCreate(
            [
                'session_type' => $type->value,
                'session_id' => $session->getKey(),
            ],
            [
                'academy_id' => $session->academy_id,
                'student_id' => $session->student_id,
                'module_key' => $moduleKey,
                'raw_score' => $totals['score'],
                // PTE reports on 0–90; the raw total already is when max is 90.
                'scaled_score' => $totals['max'] > 0.0 ? round($percentage / 100 * 90, 2) : 0.0,
                'percentage' => $percentage,
                'breakdown' => $breakdown,
                'published_at' => $publishedAt,
            ]
        );

        return $score;
    }

    /**
     * @return Collection<int, Answer>
     */
    private function answers(PracticeSession|ExamSession $session): Collection
    {
        return Answer::query()
            ->forSession($session->sessionType(), (int) $session->getKey())
            ->get();
    }

    /**
     * @param  Collection<int, Answer>  $answers
     * @return array{score: float, max: float}
     */
    private function totals(Collection $answers): array
    {
        return [
            'score' => round((float) $answers->sum(fn (Answer $a): float => (float) ($a->score ?? 0.0)), 2),
            'max' => round((float) $answers->sum(fn (Answer $a): float => (float) ($a->max_score ?? 0.0)), 2),
        ];
    }

    /**
     * @param  Collection<int, Answer>  $answers
     */
    private function pendingCount(Collection $answers): int
    {
        return $answers->filter(fn (Answer $a): bool => $a->scoring_status->isPending())->count();
    }

    /**
     * @param  Collection<int, Answer>  $answers
     * @return array<string, array{score: float, max: float, count: int, percentage: float}>
     */
    private function byType(Collection $answers): array
    {
        $buckets = [];

        foreach ($answers as $answer) {
            $type = $this->typeOf($answer);

            if ($type === null) {
                continue;
            }

            $bucket = $buckets[$type->value] ?? ['score' => 0.0, 'max' => 0.0, 'count' => 0, 'percentage' => 0.0];
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

        return $buckets;
    }

    /**
     * @param  Collection<int, Answer>  $answers
     * @return array<int, array<string, mixed>>
     */
    private function bySection(ExamSession $session, Collection $answers): array
    {
        $byQuestion = $answers->keyBy(fn (Answer $answer): int => (int) $answer->question_id);
        $sections = [];

        foreach ($session->sectionSnapshots() as $section) {
            $score = 0.0;
            $max = 0.0;

            foreach ((array) ($section['questions'] ?? []) as $question) {
                $questionId = (int) ($question['question_id'] ?? 0);
                $answer = $byQuestion->get($questionId);

                $score += (float) ($answer?->score ?? 0.0);
                // Unanswered questions still count against the section maximum.
                $max += (float) ($answer?->max_score ?? ($question['max_score'] ?? 0.0));
            }

            $sections[] = [
                'id' => (int) ($section['id'] ?? 0),
                'title' => (string) ($section['title'] ?? ''),
                'module_key' => $section['module_key'] ?? null,
                'score' => round($score, 2),
                'max' => round($max, 2),
                'percentage' => $max > 0.0 ? round($score / $max * 100, 2) : 0.0,
            ];
        }

        return $sections;
    }

    private function typeOf(Answer $answer): ?QuestionType
    {
        $breakdownType = $answer->payloadValue('question_type');

        if (is_string($breakdownType) && ($type = QuestionType::tryFrom($breakdownType)) instanceof QuestionType) {
            return $type;
        }

        try {
            return $answer->questionType();
        } catch (Throwable) {
            // A deleted question must not break a report card.
            return null;
        }
    }
}
