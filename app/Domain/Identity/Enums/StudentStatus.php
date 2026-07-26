<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * @see docs/07-database-schema.md §4
 */
enum StudentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return __("academy.student_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
            self::Blocked => 'danger',
        };
    }

    /** Whether the bot should keep serving this student. */
    public function canPractice(): bool
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
