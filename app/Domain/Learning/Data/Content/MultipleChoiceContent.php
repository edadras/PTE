<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Multiple Choice, listening or reading, single or multiple answer.
 *
 * The options live in the question_options table; what is kept here is the
 * stimulus (passage or audio) and the answer cardinality.
 */
final readonly class MultipleChoiceContent extends QuestionContent
{
    public function __construct(
        public string $prompt,
        public ?string $passage = null,
        public ?string $audioKey = null,
        public ?string $transcript = null,
        public bool $multiple = false,
        public bool $negativeMarking = true,
        public ?int $durationSeconds = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            prompt: self::string($data, 'prompt'),
            passage: self::nullableString($data, 'passage'),
            audioKey: self::nullableString($data, 'audio_key'),
            transcript: self::nullableString($data, 'transcript'),
            multiple: (bool) ($data['multiple'] ?? false),
            // PTE penalises wrong picks on multi-answer items; keeping the flag
            // in content lets an academy soften that for practice.
            negativeMarking: (bool) ($data['negative_marking'] ?? true),
            durationSeconds: self::nullableInt($data, 'duration_seconds'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'prompt' => $this->prompt,
            'passage' => $this->passage,
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'multiple' => $this->multiple,
            'negative_marking' => $this->negativeMarking,
            'duration_seconds' => $this->durationSeconds,
        ]);
    }
}
