<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Services;

use App\Domain\Assessment\Data\QuestionContext;
use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Events\ExamSessionExpired;
use App\Domain\Assessment\Events\ExamSessionSubmitted;
use App\Domain\Assessment\Exceptions\ExamNotAvailable;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamSection;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Identity\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs one attempt from start to submission.
 *
 * Two things are deliberately server-side and never trusted to the client:
 * the snapshot (what the student is graded against) and the clock (when each
 * section and the paper as a whole run out). A Telegram client can be closed,
 * reopened, or lied to; neither of those can move a deadline.
 *
 * @see docs/05-modules-exams-practice.md §5
 */
final class ExamRunner
{
    public function __construct(
        private readonly ExamQuestionResolver $resolver,
        private readonly ScoreAggregator $aggregator = new ScoreAggregator,
    ) {}

    public function start(Exam $exam, Student $student): ExamSession
    {
        if (! $exam->status->isStartable()) {
            throw ExamNotAvailable::notPublished((int) $exam->getKey());
        }

        if (! $exam->isOpenAt()) {
            throw ExamNotAvailable::outsideWindow((int) $exam->getKey());
        }

        $previous = ExamSession::query()
            ->where('exam_id', $exam->getKey())
            ->where('student_id', $student->getKey())
            ->count();

        if ($previous >= $exam->maxAttempts()) {
            throw ExamNotAvailable::attemptsExhausted((int) $exam->getKey(), $exam->maxAttempts());
        }

        $snapshot = $this->buildSnapshot($exam, $student);

        if (($snapshot['question_count'] ?? 0) === 0) {
            throw ExamNotAvailable::empty((int) $exam->getKey());
        }

        $startedAt = now();

        return DB::transaction(function () use ($exam, $student, $snapshot, $startedAt, $previous): ExamSession {
            /** @var ExamSession $session */
            $session = ExamSession::query()->create([
                'exam_id' => $exam->getKey(),
                'student_id' => $student->getKey(),
                'attempt_number' => $previous + 1,
                'status' => SessionStatus::InProgress,
                'current_section_id' => $snapshot['sections'][0]['id'] ?? null,
                'snapshot' => $snapshot,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes(max(1, $exam->duration_minutes)),
            ]);

            return $session;
        });
    }

    /**
     * State needed to put a returning student back where they were:
     * "your exam stopped at question 7, continue?".
     *
     * @return array<string, mixed>
     */
    public function resume(ExamSession $session): array
    {
        $answered = Answer::query()
            ->forSession(SessionType::Exam, (int) $session->getKey())
            ->pluck('question_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $sectionId = $session->current_section_id ?? ($session->sectionSnapshots()[0]['id'] ?? null);
        $section = $sectionId === null ? null : $session->sectionSnapshot((int) $sectionId);

        $remaining = [];

        foreach ($session->sectionSnapshots() as $snapshotSection) {
            foreach ((array) ($snapshotSection['questions'] ?? []) as $question) {
                $questionId = (int) ($question['question_id'] ?? 0);

                if (! in_array($questionId, $answered, true)) {
                    $remaining[] = [
                        'section_id' => (int) ($snapshotSection['id'] ?? 0),
                        'question_id' => $questionId,
                        'sort_order' => (int) ($question['sort_order'] ?? 0),
                    ];
                }
            }
        }

        return [
            'session_id' => (int) $session->getKey(),
            'status' => $session->status->value,
            'current_section' => $section,
            'answered_count' => count($answered),
            'total_questions' => $session->totalQuestions(),
            'next' => $remaining[0] ?? null,
            'remaining' => $remaining,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'section_deadline' => $sectionId === null
                ? null
                : $this->sectionDeadline($session, (int) $sectionId)?->toIso8601String(),
            'expired' => $session->hasExpired(),
        ];
    }

    /**
     * Deadline for a section, derived from the session's own start time and the
     * durations frozen in the snapshot. Sections are assumed to run in order,
     * which is what `rules.ordered_sections` enforces.
     */
    public function sectionDeadline(ExamSession $session, int $sectionId): ?Carbon
    {
        if ($session->started_at === null) {
            return null;
        }

        $elapsed = 0;

        foreach ($session->sectionSnapshots() as $section) {
            $elapsed += max(0, (int) ($section['duration_minutes'] ?? 0));

            if ((int) ($section['id'] ?? 0) === $sectionId) {
                $deadline = $session->started_at->copy()->addMinutes($elapsed);

                // Never let the sum of section timers outlive the paper itself.
                return $session->expires_at !== null && $deadline->greaterThan($session->expires_at)
                    ? $session->expires_at->copy()
                    : $deadline;
            }
        }

        return $session->expires_at?->copy();
    }

    public function secondsRemainingInSection(ExamSession $session, int $sectionId): int
    {
        $deadline = $this->sectionDeadline($session, $sectionId);

        return $deadline === null ? 0 : (int) max(0, now()->diffInSeconds($deadline, false));
    }

    /** Moves to the next section, or returns null when the paper is finished. */
    public function advance(ExamSession $session): ?int
    {
        $sections = $session->sectionSnapshots();
        $currentIndex = null;

        foreach ($sections as $index => $section) {
            if ((int) ($section['id'] ?? 0) === (int) $session->current_section_id) {
                $currentIndex = $index;
                break;
            }
        }

        $next = $sections[($currentIndex ?? -1) + 1] ?? null;

        if ($next === null) {
            return null;
        }

        $session->forceFill(['current_section_id' => (int) $next['id']])->save();

        return (int) $next['id'];
    }

    /** The frozen grading context for a question of this attempt. */
    public function contextFor(ExamSession $session, int $questionId): ?QuestionContext
    {
        return $session->contextFor($questionId);
    }

    public function submit(ExamSession $session, bool $automatic = false): ExamSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        $session->forceFill([
            'status' => SessionStatus::Submitted,
            'submitted_at' => now(),
        ])->save();

        ExamSessionSubmitted::dispatch($session, $automatic);

        // Deterministic answers are already scored; only when the AI queue owes
        // us nothing can the report card be produced immediately.
        if ($this->aggregator->hasPendingAnswers($session)) {
            $session->forceFill(['status' => SessionStatus::Scoring])->save();

            return $session;
        }

        $this->aggregator->aggregate($session);

        return $session->refresh();
    }

    /** Auto-submit for a session whose clock ran out. */
    public function expire(ExamSession $session): ExamSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        $session->forceFill([
            'status' => SessionStatus::Expired,
            'submitted_at' => $session->submitted_at ?? now(),
        ])->save();

        ExamSessionExpired::dispatch($session);

        if ($this->aggregator->hasPendingAnswers($session)) {
            return $session;
        }

        $this->aggregator->aggregate($session);

        return $session->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(Exam $exam, Student $student): array
    {
        $shuffle = (bool) $exam->rule('shuffle_questions', true);
        $sections = [];
        $questionCount = 0;

        foreach ($exam->sections()->get() as $section) {
            /** @var ExamSection $section */
            $resolved = $this->resolver->resolve($section, $student, $shuffle);
            $questionCount += $resolved->count();

            $sections[] = [
                'id' => (int) $section->getKey(),
                'title' => $section->title,
                'module_key' => $section->module_key->value,
                'duration_minutes' => (int) $section->duration_minutes,
                'score' => (float) $section->score,
                'sort_order' => (int) $section->sort_order,
                'selection_mode' => $section->selection_mode->value,
                'questions' => $resolved->map(function (array $item): array {
                    /** @var Model $question */
                    $question = $item['question'];
                    $context = QuestionContext::fromQuestion($question, (float) $item['score']);

                    return array_merge($context->toArray(), [
                        'question_id' => (int) $question->getKey(),
                        'sort_order' => (int) $item['sort_order'],
                        'score' => (float) $item['score'],
                        'title' => $question->getAttribute('title'),
                    ]);
                })->all(),
            ];
        }

        return [
            'exam' => [
                'id' => (int) $exam->getKey(),
                'title' => $exam->title,
                'duration_minutes' => (int) $exam->duration_minutes,
                'total_score' => (float) $exam->total_score,
                'passing_score' => $exam->passing_score === null ? null : (float) $exam->passing_score,
                'rules' => $exam->rules ?? [],
            ],
            'sections' => $sections,
            'question_count' => $questionCount,
            'taken_at' => now()->toIso8601String(),
            'version' => 1,
        ];
    }
}
