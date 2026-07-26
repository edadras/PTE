<?php

declare(strict_types=1);

namespace App\Domain\Support\Enums;

/**
 * Who wrote a ticket message. `sender_id` means a student id or a user id
 * depending on this value — the two id spaces are unrelated, so nothing may
 * join on `sender_id` without first filtering on `sender_type`.
 */
enum TicketSender: string
{
    case Student = 'student';
    case Staff = 'staff';
    case System = 'system';

    public function label(): string
    {
        return __("support.sender.{$this->value}");
    }

    public function isStaff(): bool
    {
        return $this === self::Staff;
    }
}
