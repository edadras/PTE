<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use InvalidArgumentException;

/**
 * Guards the one invariant the whole scoring model rests on: weights sum to
 * exactly 100. A rubric that does not is silently wrong for every student it
 * touches, so it may never be persisted.
 */
final class InvalidRubricException extends InvalidArgumentException
{
    public static function weightsDoNotSumTo100(int|float $actual): self
    {
        return new self(sprintf('Rubric criterion weights must sum to exactly 100, got %s.', (string) $actual));
    }

    public static function emptyCriteria(): self
    {
        return new self('A rubric must define at least one criterion.');
    }

    public static function duplicateCriterion(string $key): self
    {
        return new self("Duplicate rubric criterion key [{$key}].");
    }

    public static function malformedCriterion(string $detail): self
    {
        return new self("Malformed rubric criterion: {$detail}.");
    }

    public static function invalidScale(int $min, int $max): self
    {
        return new self("Rubric scale [{$min}..{$max}] is invalid; max must exceed min.");
    }
}
