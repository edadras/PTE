<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Read Aloud — the student reads a short passage out loud.
 */
final readonly class ReadAloudContent extends QuestionContent
{
    public function __construct(
        public string $text,
        public int $prepSeconds = 40,
        public int $recordSeconds = 40,
        public ?int $wordCount = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $text = self::string($data, 'text');

        return new self(
            text: $text,
            prepSeconds: self::int($data, 'prep_seconds', 40),
            recordSeconds: self::int($data, 'record_seconds', 40),
            wordCount: self::nullableInt($data, 'word_count') ?? str_word_count($text),
        );
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'prep_seconds' => $this->prepSeconds,
            'record_seconds' => $this->recordSeconds,
            'word_count' => $this->wordCount ?? str_word_count($this->text),
        ];
    }
}
