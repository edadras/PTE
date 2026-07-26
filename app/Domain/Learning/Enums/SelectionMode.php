<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/**
 * How the practice engine picks the next questions.
 *
 * @see docs/05-modules-exams-practice.md §4
 */
enum SelectionMode: string
{
    case Random = 'random';
    case Sequential = 'sequential';
    case Adaptive = 'adaptive';

    public function label(): string
    {
        return __('learning.selection_mode.'.$this->value);
    }

    public static function fromMixed(self|string|null $mode): self
    {
        if ($mode instanceof self) {
            return $mode;
        }

        return self::tryFrom((string) $mode) ?? self::Random;
    }
}
