<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Fill in the Blanks — listening (type it), reading (dropdown) and
 * reading-and-writing (drag & drop) all share this shape.
 *
 * The passage carries {{1}} … {{n}} placeholders; each blank declares its
 * accepted answers and, for dropdown/drag variants, the distractors shown.
 */
final readonly class FillInBlanksContent extends QuestionContent
{
    /**
     * @param  array<int, array{position: int, answers: array<int, string>, options: array<int, string>}>  $blanks
     */
    public function __construct(
        public string $passage,
        public array $blanks,
        public ?string $audioKey = null,
        public ?string $transcript = null,
        public string $inputMode = 'text',
        public bool $caseSensitive = false,
        public ?int $durationSeconds = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $blanks = [];
        $position = 0;

        foreach (self::list($data, 'blanks') as $blank) {
            $position++;

            if (! is_array($blank)) {
                continue;
            }

            $answers = isset($blank['answers']) && is_array($blank['answers'])
                ? array_values(array_map(static fn (mixed $a): string => is_scalar($a) ? (string) $a : '', $blank['answers']))
                : (isset($blank['answer']) && is_scalar($blank['answer']) ? [(string) $blank['answer']] : []);

            $blanks[] = [
                'position' => is_numeric($blank['position'] ?? null) ? (int) $blank['position'] : $position,
                'answers' => $answers,
                'options' => isset($blank['options']) && is_array($blank['options'])
                    ? array_values(array_map(static fn (mixed $o): string => is_scalar($o) ? (string) $o : '', $blank['options']))
                    : [],
            ];
        }

        return new self(
            passage: self::string($data, 'passage'),
            blanks: $blanks,
            audioKey: self::nullableString($data, 'audio_key'),
            transcript: self::nullableString($data, 'transcript'),
            inputMode: self::string($data, 'input_mode', 'text'),
            caseSensitive: (bool) ($data['case_sensitive'] ?? false),
            durationSeconds: self::nullableInt($data, 'duration_seconds'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'passage' => $this->passage,
            'blanks' => $this->blanks,
            'audio_key' => $this->audioKey,
            'transcript' => $this->transcript,
            'input_mode' => $this->inputMode,
            'case_sensitive' => $this->caseSensitive,
            'duration_seconds' => $this->durationSeconds,
        ]);
    }

    public function blankCount(): int
    {
        return count($this->blanks);
    }

    /** The union of every blank's options — the shared word bank for drag & drop. */
    public function wordBank(): array
    {
        $bank = [];

        foreach ($this->blanks as $blank) {
            foreach ($blank['options'] as $option) {
                $bank[$option] = true;
            }

            foreach ($blank['answers'] as $answer) {
                $bank[$answer] = true;
            }
        }

        return array_keys($bank);
    }
}
