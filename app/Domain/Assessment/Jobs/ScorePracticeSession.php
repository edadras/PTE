<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Jobs;

use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Services\ScoreAggregator;
use App\Domain\Shared\Jobs\TenantAwareJob;

/**
 * Writes a practice session's summary once every answer has a grade.
 *
 * Rather than have the AI job try to guess when it was the last one, this job
 * simply comes back and looks again — a session with an essay in it waits a
 * couple of rounds, everything else is aggregated on the first pass.
 */
final class ScorePracticeSession extends TenantAwareJob
{
    /** Roughly ten minutes of waiting before we give up and publish what we have. */
    public int $tries = 20;

    public function __construct(int $academyId, public readonly int $sessionId)
    {
        parent::__construct($academyId);
    }

    public function handle(ScoreAggregator $aggregator): void
    {
        $session = PracticeSession::query()->find($this->sessionId);

        if ($session === null) {
            return;
        }

        if ($aggregator->hasPendingAnswers($session) && $this->attempts() < $this->tries) {
            $this->release(30);

            return;
        }

        // Last attempt: aggregate regardless. A student waiting on a report card
        // is better served by a partial one than by silence.
        $aggregator->aggregate($session);
    }
}
