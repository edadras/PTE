<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Select Missing Word — the recording is beeped out at the end and the student
 * picks the phrase that completes it. Choices live in question_options.
 */
final readonly class SelectMissingWordContent extends QuestionContent
{
    public function __construct(
        public string $audioKey,
        public string $transcript,
        public int $playCount = 1,
        public ?string $prompt = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            audioKey: self::string($data, 'audio_key'),
            transcript: self::string($data, 'transcript'),
            playCount: self::int($data, 'play_count', 1),
            prompt: self::nullableString($data, 'prompt'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'play_count' => $this->playCount,
            'prompt' => $this->prompt,
        ]);
    }
}
