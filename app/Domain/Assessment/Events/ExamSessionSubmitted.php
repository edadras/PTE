<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\ExamSession;
use Illuminate\Foundation\Events\Dispatchable;

final class ExamSessionSubmitted
{
    use Dispatchable;

    /** @param bool $automatic true when the timer submitted it, not the student. */
    public function __construct(
        public readonly ExamSession $session,
        public readonly bool $automatic = false,
    ) {}
}
