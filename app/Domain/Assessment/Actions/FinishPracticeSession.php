<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Enums\SessionStatus;
use App\Domain\Assessment\Events\PracticeSessionCompleted;
use App\Domain\Assessment\Jobs\ScorePracticeSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ScoreAggregator;

/**
 * Closes a practice session and produces its summary.
 *
 * When AI-scored answers are still in flight the summary is deferred to a job
 * rather than written half-empty — a report card that says 12/90 because the
 * essay had not come back yet is worse than one that arrives a minute later.
 */
final class FinishPracticeSession
{
    public function __construct(private readonly ScoreAggregator $aggregator = new ScoreAggregator) {}

    public function handle(PracticeSession $session, bool $abandoned = false): PracticeSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        $completedAt = now();

        $session->forceFill([
            'status' => $abandoned ? SessionStatus::Abandoned : SessionStatus::Completed,
            'completed_at' => $completedAt,
            'answered' => $session->answers()->count(),
            'duration_seconds' => $session->started_at === null
                ? null
                : (int) $session->started_at->diffInSeconds($completedAt),
        ])->save();

        if ($this->aggregator->hasPendingAnswers($session)) {
            ScorePracticeSession::dispatch((int) $session->academy_id, (int) $session->getKey())
                ->onQueue((string) config('pte.queues.ai_scoring', 'ai-scoring'))
                ->delay(now()->addSeconds(20));
        } else {
            $this->aggregator->aggregate($session);
        }

        PracticeSessionCompleted::dispatch($session);

        return $session;
    }
}
