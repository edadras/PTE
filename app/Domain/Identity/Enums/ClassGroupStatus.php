<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum ClassGroupStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return __("academy.class_group_status.{$this->value}");
    }

    public function acceptsEnrolment(): bool
    {
        return $this === self::Planned || $this === self::Active;
    }
}
