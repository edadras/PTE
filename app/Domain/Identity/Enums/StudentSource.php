<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * How a student record came into existence.
 *
 * @see docs/07-database-schema.md §4
 */
enum StudentSource: string
{
    case Telegram = 'telegram';
    case Web = 'web';
    case Import = 'import';
    case Api = 'api';

    public function label(): string
    {
        return __("academy.student_source.{$this->value}");
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
