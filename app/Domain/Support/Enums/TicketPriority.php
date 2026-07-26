<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return __("support.priority.{$this->value}");
    }

    /** Higher sorts first in a queue view. */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 40,
            self::High => 30,
            self::Normal => 20,
            self::Low => 10,
        };
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
