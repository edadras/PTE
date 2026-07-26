<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Write From Dictation — one sentence, scored word by word in PHP.
 */
final readonly class WriteFromDictationContent extends QuestionContent
{
    public function __construct(
        public string $transcript,
        public ?string $audioKey = null,
        public int $playCount = 1,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            transcript: self::string($data, 'transcript'),
            audioKey: self::nullableString($data, 'audio_key'),
            playCount: self::int($data, 'play_count', 1),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'play_count' => $this->playCount,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function words(): array
    {
        return preg_split('/\s+/u', trim($this->transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
