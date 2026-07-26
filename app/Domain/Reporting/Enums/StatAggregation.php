<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

enum StatAggregation: string
{
    case Sum = 'sum';
    case Mean = 'mean';
    case Last = 'last';

    /**
     * @param  array<int, float>  $values
     */
    public function apply(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return match ($this) {
            self::Sum => round(array_sum($values), 4),
            self::Mean => round(array_sum($values) / count($values), 4),
            self::Last => round((float) end($values), 4),
        };
    }
}
