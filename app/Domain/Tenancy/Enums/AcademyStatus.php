<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * @see docs/01-multi-tenancy.md §8
 */
enum AcademyStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function label(): string
    {
        return __("academy.status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Deleted => 'danger',
        };
    }

    /** Whether the bot answers and the panel accepts writes. */
    public function allowsWrites(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            []
        );
    }
}
