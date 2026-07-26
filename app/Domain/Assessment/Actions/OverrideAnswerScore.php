<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\ScoredBy;
use App\Domain\Assessment\Enums\ScoringStatus;
use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Assessment\Events\AnswerScoreOverridden;
use App\Domain\Assessment\Exceptions\OverrideReasonRequired;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ScoreAggregator;
use Illuminate\Support\Facades\DB;

/**
 * Teacher override of a grade.
 *
 * Three invariants make this defensible when a student disputes a result:
 *  - a written reason is mandatory;
 *  - the model's original number is preserved once and never overwritten again,
 *    so repeated edits cannot launder it away;
 *  - who did it is stamped, and an event goes to the audit log.
 */
final class OverrideAnswerScore
{
    public function __construct(private readonly ScoreAggregator $aggregator = new ScoreAggregator) {}

    /**
     * @param  array<string, mixed>|null  $breakdown
     * @param  array<string, mixed>|null  $feedback
     */
    public function handle(
        Answer $answer,
        float $score,
        string $reason,
        int $teacherId,
        ?array $breakdown = null,
        ?array $feedback = null,
    ): Answer {
        $reason = trim($reason);

        if ($reason === '') {
            throw OverrideReasonRequired::forAnswer((int) $answer->getKey());
        }

        $previous = $answer->score === null ? null : (float) $answer->score;
        $max = (float) ($answer->max_score ?? 0.0);
        $clamped = max(0.0, $max > 0.0 ? min($score, $max) : $score);

        DB::transaction(function () use ($answer, $clamped, $reason, $teacherId, $previous, $breakdown, $feedback): void {
            $answer->forceFill(array_filter([
                'score' => $clamped,
                // Written once: only the first override captures what the AI said.
                'original_ai_score' => $answer->original_ai_score ?? $previous,
                'override_reason' => $reason,
                'overridden_by' => $teacherId,
                'graded_manually' => true,
                'scored_by' => ScoredBy::Teacher,
                'scoring_status' => ScoringStatus::Scored,
                'scored_at' => now(),
                'breakdown' => $breakdown ?? $answer->breakdown,
                'feedback' => $feedback ?? $answer->feedback,
            ], static fn (mixed $value): bool => $value !== null))->save();

            $this->reaggregate($answer);
        });

        AnswerScoreOverridden::dispatch($answer, $previous, $clamped, $reason, $teacherId);

        return $answer;
    }

    /** A changed answer invalidates the session summary built from it. */
    private function reaggregate(Answer $answer): void
    {
        $session = $answer->session_type === SessionType::Exam
            ? ExamSession::query()->find($answer->session_id)
            : PracticeSession::query()->find($answer->session_id);

        if ($session === null || $session->isOpen()) {
            return;
        }

        $this->aggregator->aggregate($session);
    }
}
