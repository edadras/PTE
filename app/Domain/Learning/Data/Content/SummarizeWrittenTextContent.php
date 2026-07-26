<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Summarize Written Text — one single sentence of 5–75 words.
 */
final readonly class SummarizeWrittenTextContent extends QuestionContent
{
    /**
     * @param  array<int, string>  $keyPoints
     */
    public function __construct(
        public string $passage,
        public int $minWords = 5,
        public int $maxWords = 75,
        public int $durationMinutes = 10,
        public array $keyPoints = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            passage: self::string($data, 'passage'),
            minWords: self::int($data, 'min_words', 5),
            maxWords: self::int($data, 'max_words', 75),
            durationMinutes: self::int($data, 'duration_minutes', 10),
            keyPoints: self::stringList($data, 'key_points'),
        );
    }

    public function toArray(): array
    {
        return [
            'passage' => $this->passage,
            'min_words' => $this->minWords,
            'max_words' => $this->maxWords,
            'duration_minutes' => $this->durationMinutes,
            'key_points' => $this->keyPoints,
        ];
    }
}
