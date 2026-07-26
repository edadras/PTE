<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\Data\SubmitAnswerData;
use App\Domain\Assessment\Exceptions\SessionNotActive;
use App\Domain\Assessment\Models\Answer;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Assessment\Services\ExamRunner;
use Illuminate\Support\Facades\DB;

/**
 * Records an answer inside an exam attempt.
 *
 * Unlike practice, the question payload comes from the session snapshot rather
 * than the live question row, and the server clock decides whether the answer
 * arrived in time. An answer that lands after the paper expired closes the
 * session instead of being quietly accepted.
 */
final class SubmitExamAnswer
{
    public function __construct(
        private readonly SubmitAnswer $submit,
        private readonly ExamRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $answerData
     */
    public function handle(
        ExamSession $session,
        int $questionId,
        array $answerData = [],
        ?string $mediaPath = null,
        ?string $transcript = null,
        bool $skipped = false,
    ): Answer {
        if (! $session->isOpen()) {
            throw SessionNotActive::is($session->status);
        }

        if ($session->hasExpired()) {
            $this->runner->expire($session);

            throw SessionNotActive::is($session->refresh()->status);
        }

        $snapshot = $session->questionSnapshot($questionId);
        $context = $session->contextFor($questionId);

        if ($snapshot === null || $context === null) {
            throw SessionNotActive::is($session->status);
        }

        $data = SubmitAnswerData::forExam(
            sessionId: (int) $session->getKey(),
            questionId: $questionId,
            studentId: (int) $session->student_id,
            answerData: $answerData,
            maxScore: (float) ($snapshot['score'] ?? $context->maxScore),
            questionType: $context->type,
            questionPayload: $context->toArray(),
            mediaPath: $mediaPath,
            transcript: $transcript,
            skipped: $skipped,
        );

        return DB::transaction(function () use ($data, $session, $snapshot): Answer {
            $answer = $this->submit->handle($data);

            // Autosave: the section the student is in is part of the resume state,
            // so it is written with every answer, not at section boundaries.
            $sectionId = $this->sectionOf($session, (int) ($snapshot['question_id'] ?? 0));

            if ($sectionId !== null && $sectionId !== (int) $session->current_section_id) {
                $session->forceFill(['current_section_id' => $sectionId])->save();
            }

            return $answer;
        });
    }

    private function sectionOf(ExamSession $session, int $questionId): ?int
    {
        foreach ($session->sectionSnapshots() as $section) {
            foreach ((array) ($section['questions'] ?? []) as $question) {
                if ((int) ($question['question_id'] ?? 0) === $questionId) {
                    return (int) ($section['id'] ?? 0);
                }
            }
        }

        return null;
    }
}
