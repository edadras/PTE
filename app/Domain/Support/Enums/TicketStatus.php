<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

/**
 * @see docs/07-database-schema.md §10
 */
enum TicketStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';

    public function label(): string
    {
        return __("support.status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Pending => 'warning',
            self::Closed => 'success',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
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
