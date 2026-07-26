<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use RuntimeException;

/**
 * The same Telegram bot is already connected to a different academy.
 *
 * Two academies sharing a bot would mean two webhook URLs fighting over one
 * setWebhook slot, and cross-tenant delivery of student messages.
 */
final class TokenAlreadyInUseException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $botUserId = null)
    {
        parent::__construct($message);
    }

    public static function forBotUser(int $botUserId): self
    {
        return new self(
            "Telegram bot {$botUserId} is already connected to another academy.",
            $botUserId
        );
    }

    public function friendlyKey(): string
    {
        return 'telegram.connect_error.token_in_use';
    }
}
