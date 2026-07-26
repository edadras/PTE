<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Write Essay — 200–300 words in 20 minutes.
 */
final readonly class EssayContent extends QuestionContent
{
    public function __construct(
        public string $prompt,
        public int $minWords = 200,
        public int $maxWords = 300,
        public int $durationMinutes = 20,
        public ?string $essayType = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            prompt: self::string($data, 'prompt'),
            minWords: self::int($data, 'min_words', 200),
            maxWords: self::int($data, 'max_words', 300),
            durationMinutes: self::int($data, 'duration_minutes', 20),
            essayType: self::nullableString($data, 'essay_type'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'prompt' => $this->prompt,
            'min_words' => $this->minWords,
            'max_words' => $this->maxWords,
            'duration_minutes' => $this->durationMinutes,
            'essay_type' => $this->essayType,
        ]);
    }
}
