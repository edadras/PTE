<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Assessment\Events\AnswerScoreOverridden;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;

/**
 * Mandatory audit (docs/02 §7): a teacher changing an AI score, with the reason.
 *
 * This is the entry a disputed grade is settled with, so it records the model's
 * original number as well as the previous one — after a second override those
 * are no longer the same value.
 */
final class RecordScoreOverride
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(AnswerScoreOverridden $event): void
    {
        $answer = $event->answer;

        $this->recorder->record(
            AuditAction::ScoreOverridden,
            $answer,
            ['score' => $event->previousScore],
            [
                'score' => $event->newScore,
                'reason' => $event->reason,
                'overridden_by' => $event->overriddenBy,
                'original_ai_score' => $answer->original_ai_score,
                'session_type' => $answer->session_type->value,
                'session_id' => $answer->session_id,
                'student_id' => $answer->student_id,
            ],
            (int) $answer->academy_id,
        );
    }
}
