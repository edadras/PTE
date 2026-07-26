<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use RuntimeException;

/**
 * The academy is out of AI budget for the period.
 *
 * This must never reach a student as an error: algorithmic practice keeps
 * working and the AI-scored answer is parked for manual review (docs/06 §7.2).
 */
final class QuotaExceededException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $metric,
        public readonly int $academyId,
    ) {
        parent::__construct($message);
    }

    public static function forMetric(int $academyId, string $metric): self
    {
        return new self(
            "Academy [{$academyId}] has exhausted its [{$metric}] quota for the current period.",
            $metric,
            $academyId,
        );
    }

    /** Student-facing copy — deliberately vague about the commercial reason. */
    public function studentMessage(): string
    {
        return __('ai.messages.result_delayed');
    }
}
