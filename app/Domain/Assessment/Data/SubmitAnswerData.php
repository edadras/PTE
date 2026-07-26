<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Data;

use App\Domain\Assessment\Enums\SessionType;
use App\Domain\Learning\Enums\QuestionType;

/**
 * Everything needed to record one submitted answer, from any surface (bot, API,
 * panel). The optional questionType/questionPayload pair is how an exam passes
 * its frozen snapshot down so grading never re-reads a question that may have
 * been edited since the session started.
 */
final readonly class SubmitAnswerData
{
    /**
     * @param  array<string, mixed>  $answerData
     * @param  array<string, mixed>|null  $transcriptMeta
     * @param  array<string, mixed>|null  $questionPayload
     */
    public function __construct(
        public SessionType $sessionType,
        public int $sessionId,
        public int $questionId,
        public int $studentId,
        public array $answerData = [],
        public ?string $mediaPath = null,
        public ?string $transcript = null,
        public ?array $transcriptMeta = null,
        public ?float $maxScore = null,
        public ?QuestionType $questionType = null,
        public ?array $questionPayload = null,
        public bool $skipped = false,
    ) {}

    /**
     * @param  array<string, mixed>  $answerData
     */
    public static function forPractice(
        int $sessionId,
        int $questionId,
        int $studentId,
        array $answerData = [],
        ?string $mediaPath = null,
        ?string $transcript = null,
        bool $skipped = false,
    ): self {
        return new self(
            sessionType: SessionType::Practice,
            sessionId: $sessionId,
            questionId: $questionId,
            studentId: $studentId,
            answerData: $answerData,
            mediaPath: $mediaPath,
            transcript: $transcript,
            skipped: $skipped,
        );
    }

    /**
     * @param  array<string, mixed>  $answerData
     * @param  array<string, mixed>|null  $questionPayload
     */
    public static function forExam(
        int $sessionId,
        int $questionId,
        int $studentId,
        array $answerData = [],
        ?float $maxScore = null,
        ?QuestionType $questionType = null,
        ?array $questionPayload = null,
        ?string $mediaPath = null,
        ?string $transcript = null,
        bool $skipped = false,
    ): self {
        return new self(
            sessionType: SessionType::Exam,
            sessionId: $sessionId,
            questionId: $questionId,
            studentId: $studentId,
            answerData: $answerData,
            mediaPath: $mediaPath,
            transcript: $transcript,
            maxScore: $maxScore,
            questionType: $questionType,
            questionPayload: $questionPayload,
            skipped: $skipped,
        );
    }

    /** Telegram hands us a file id long before the audio itself exists on disk. */
    public function telegramFileId(): ?string
    {
        $id = $this->answerData['telegram_file_id'] ?? $this->answerData['file_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function withTranscript(string $transcript): self
    {
        return new self(
            $this->sessionType,
            $this->sessionId,
            $this->questionId,
            $this->studentId,
            $this->answerData,
            $this->mediaPath,
            $transcript,
            $this->transcriptMeta,
            $this->maxScore,
            $this->questionType,
            $this->questionPayload,
            $this->skipped,
        );
    }
}
