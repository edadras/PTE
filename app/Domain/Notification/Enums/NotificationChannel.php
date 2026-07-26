<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

use App\Domain\Tenancy\Models\MessageTemplate;

/**
 * @see docs/07-database-schema.md §10
 */
enum NotificationChannel: string
{
    case Telegram = 'telegram';
    case Email = 'email';
    case Sms = 'sms';

    public function label(): string
    {
        return __("notifications.channel.{$this->value}");
    }

    /** The channel name MessageTemplate stores its overrides under. */
    public function templateChannel(): string
    {
        return match ($this) {
            self::Telegram => MessageTemplate::CHANNEL_TELEGRAM,
            self::Email => MessageTemplate::CHANNEL_EMAIL,
            self::Sms => MessageTemplate::CHANNEL_SMS,
        };
    }

    /**
     * SMS costs money per message and is capped at 70 Persian characters per
     * segment, so anything long is truncated rather than silently billed twice.
     */
    public function maxLength(): ?int
    {
        return match ($this) {
            self::Sms => 460,
            self::Telegram => 4096,
            self::Email => null,
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
