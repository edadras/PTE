<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Answer Short Question — one or two words, matched against a keyword list.
 */
final readonly class AnswerShortQuestionContent extends QuestionContent
{
    /**
     * @param  array<int, string>  $acceptedAnswers  Every spelling/synonym counted as correct.
     */
    public function __construct(
        public string $audioKey,
        public string $transcript,
        public array $acceptedAnswers,
        public int $recordSeconds = 10,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            audioKey: self::string($data, 'audio_key'),
            transcript: self::string($data, 'transcript'),
            acceptedAnswers: self::stringList($data, 'accepted_answers'),
            recordSeconds: self::int($data, 'record_seconds', 10),
        );
    }

    public function toArray(): array
    {
        return [
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'accepted_answers' => $this->acceptedAnswers,
            'record_seconds' => $this->recordSeconds,
        ];
    }
}
