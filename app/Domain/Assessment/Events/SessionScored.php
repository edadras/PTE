<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Models\PracticeSession;
use App\Domain\Assessment\Models\Score;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session summary was written. The notification layer turns this into the
 * student's report card message.
 */
final class SessionScored
{
    use Dispatchable;

    public function __construct(
        public readonly Score $score,
        public readonly PracticeSession|ExamSession $session,
    ) {}
}
