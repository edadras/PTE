<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

enum ScheduledContentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return __("notifications.scheduled_status.{$this->value}");
    }

    public function isDue(): bool
    {
        return $this === self::Pending;
    }
}
