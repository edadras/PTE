<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Events;

use App\Domain\Assessment\Models\Answer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An answer reached a final score, from any source. Reporting and the question
 * difficulty_index recalculation both hang off this.
 */
final class AnswerScored
{
    use Dispatchable;

    public function __construct(public readonly Answer $answer) {}
}
