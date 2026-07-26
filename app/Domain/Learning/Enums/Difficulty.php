<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * The author's declared difficulty. The measured counterpart is
 * questions.difficulty_index, which is computed from real answers.
 */
enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return __('learning.difficulty.'.$this->value);
    }

    /**
     * Where this band sits on the 0–1 difficulty_index scale.
     *
     * @return array{0: float, 1: float}
     */
    public function indexRange(): array
    {
        return match ($this) {
            self::Easy => [0.00, 0.35],
            self::Medium => [0.35, 0.65],
            self::Hard => [0.65, 1.00],
        };
    }

    public function defaultIndex(): float
    {
        return match ($this) {
            self::Easy => 0.25,
            self::Medium => 0.50,
            self::Hard => 0.75,
        };
    }

    /** Bucket a measured index back into a human-readable band. */
    public static function fromIndex(float $index): self
    {
        return match (true) {
            $index < 0.35 => self::Easy,
            $index < 0.65 => self::Medium,
            default => self::Hard,
        };
    }
}
