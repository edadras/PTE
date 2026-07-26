<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Status of a staff membership inside one academy.
 *
 * @see docs/07-database-schema.md §3
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Suspended = 'suspended';

    public function label(): string
    {
        return __("academy.membership_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Invited => 'warning',
            self::Suspended => 'danger',
        };
    }

    /** Only an accepted membership grants the role's permissions. */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
