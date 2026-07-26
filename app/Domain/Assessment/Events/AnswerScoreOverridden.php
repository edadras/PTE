<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\Answer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A teacher changed a grade. Carries the before/after pair explicitly so the
 * audit listener never has to guess which of the answer's score columns held the
 * previous value.
 */
final class AnswerScoreOverridden
{
    use Dispatchable;

    public function __construct(
        public readonly Answer $answer,
        public readonly ?float $previousScore,
        public readonly float $newScore,
        public readonly string $reason,
        public readonly int $overriddenBy,
    ) {}
}
