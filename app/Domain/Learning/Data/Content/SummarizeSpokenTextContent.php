<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Summarize Spoken Text — listen to a lecture, write a 50–70 word summary.
 */
final readonly class SummarizeSpokenTextContent extends QuestionContent
{
    /**
     * @param  array<int, string>  $keyPoints
     */
    public function __construct(
        public string $audioKey,
        public string $transcript,
        public int $minWords = 50,
        public int $maxWords = 70,
        public int $durationMinutes = 10,
        public array $keyPoints = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            audioKey: self::string($data, 'audio_key'),
            transcript: self::string($data, 'transcript'),
            minWords: self::int($data, 'min_words', 50),
            maxWords: self::int($data, 'max_words', 70),
            durationMinutes: self::int($data, 'duration_minutes', 10),
            keyPoints: self::stringList($data, 'key_points'),
        );
    }

    public function toArray(): array
    {
        return [
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'min_words' => $this->minWords,
            'max_words' => $this->maxWords,
            'duration_minutes' => $this->durationMinutes,
            'key_points' => $this->keyPoints,
        ];
    }
}
