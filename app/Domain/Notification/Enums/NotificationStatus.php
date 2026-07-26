<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __("notifications.status.{$this->value}");
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Failed, self::Skipped], true);
    }
}
