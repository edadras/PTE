<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * Rolled up by the CheckBotHealth job every five minutes.
 *
 * @see docs/04-telegram-layer.md §10
 */
enum BotHealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Failing = 'failing';

    public function label(): string
    {
        return __('telegram.health.'.$this->value);
    }

    /** Hex colour for the Super Admin dashboard badge. */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Degraded => 'warning',
            self::Failing => 'danger',
        };
    }

    public function needsAttention(): bool
    {
        return $this !== self::Ok;
    }
}
