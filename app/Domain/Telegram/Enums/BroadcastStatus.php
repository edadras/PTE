<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

enum BroadcastStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return __('telegram.broadcast_status.'.$this->value);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Scheduled, self::Running, self::Paused], true);
    }
}
