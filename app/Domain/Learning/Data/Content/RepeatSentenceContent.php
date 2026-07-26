<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Repeat Sentence — audio is played once, the student repeats it verbatim.
 */
final readonly class RepeatSentenceContent extends QuestionContent
{
    public function __construct(
        public string $audioKey,
        public string $transcript,
        public int $recordSeconds = 15,
        public int $playCount = 1,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            audioKey: self::string($data, 'audio_key'),
            transcript: self::string($data, 'transcript'),
            recordSeconds: self::int($data, 'record_seconds', 15),
            playCount: self::int($data, 'play_count', 1),
        );
    }

    public function toArray(): array
    {
        return [
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'record_seconds' => $this->recordSeconds,
            'play_count' => $this->playCount,
        ];
    }
}
