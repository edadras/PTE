<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

use BackedEnum;

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

    /**
     * Tolerates the equivalent enum from another context (Assessment passes its
     * own PracticeSelection through) as well as a plain string.
     */
    public static function fromMixed(BackedEnum|string|null $mode): self
    {
        if ($mode instanceof self) {
            return $mode;
        }

        if ($mode instanceof BackedEnum) {
            $mode = $mode->value;
        }

        return is_string($mode) ? (self::tryFrom($mode) ?? self::Random) : self::Random;
    }
}
