<?php

declare(strict_types=1);

namespace App\Domain\Learning\Data\Content;

use App\Domain\Learning\Data\QuestionContent;

/**
 * Re-order Paragraphs — scored on adjacent pairs, not absolute positions.
 */
final readonly class ReorderParagraphsContent extends QuestionContent
{
    /**
     * @param  array<int, array{key: string, text: string}>  $paragraphs
     * @param  array<int, string>  $correctOrder
     */
    public function __construct(
        public array $paragraphs,
        public array $correctOrder,
        public ?int $durationSeconds = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $paragraphs = [];

        foreach (self::list($data, 'paragraphs') as $paragraph) {
            if (! is_array($paragraph)) {
                continue;
            }

            $paragraphs[] = [
                'key' => is_scalar($paragraph['key'] ?? null) ? (string) $paragraph['key'] : '',
                'text' => is_scalar($paragraph['text'] ?? null) ? (string) $paragraph['text'] : '',
            ];
        }

        return new self(
            paragraphs: $paragraphs,
            correctOrder: self::stringList($data, 'correct_order'),
            durationSeconds: self::nullableInt($data, 'duration_seconds'),
        );
    }

    public function toArray(): array
    {
        return self::withoutNulls([
            'paragraphs' => $this->paragraphs,
            'correct_order' => $this->correctOrder,
            'duration_seconds' => $this->durationSeconds,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map(static fn (array $paragraph): string => $paragraph['key'], $this->paragraphs);
    }

    /** Adjacent-pair count is the maximum score for this item type. */
    public function maxPairs(): int
    {
        return max(0, count($this->correctOrder) - 1);
    }
}
