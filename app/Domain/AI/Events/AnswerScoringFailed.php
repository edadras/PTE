<?php

declare(strict_types=1);

namespace App\Domain\AI\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Every provider failed for one answer. The student has already been told their
 * result is on its way; this is what tells the academy (docs/06 §8).
 */
final class AnswerScoringFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $academyId,
        public readonly int $answerId,
        public readonly string $taskKey,
        public readonly string $reason,
    ) {}
}
