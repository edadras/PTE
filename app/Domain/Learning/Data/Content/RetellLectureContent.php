<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Re-tell Lecture — the student listens to a lecture then summarises it aloud.
 */
final readonly class RetellLectureContent extends QuestionContent
{
    /**
     * @param  array<int, string>  $keyPoints
     */
    public function __construct(
        public string $audioKey,
        public string $transcript,
        public int $prepSeconds = 10,
        public int $recordSeconds = 40,
        public ?string $imageKey = null,
        public array $keyPoints = [],
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            audioKey: self::string($data, 'audio_key'),
            transcript: self::string($data, 'transcript'),
            prepSeconds: self::int($data, 'prep_seconds', 10),
            recordSeconds: self::int($data, 'record_seconds', 40),
            imageKey: self::nullableString($data, 'image_key'),
            keyPoints: self::stringList($data, 'key_points'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'prep_seconds' => $this->prepSeconds,
            'record_seconds' => $this->recordSeconds,
            'image_key' => $this->imageKey,
            'key_points' => $this->keyPoints,
        ]);
    }
}
