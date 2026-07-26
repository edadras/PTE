<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use Throwable;

/**
 * The pasted token is malformed or rejected by getMe.
 *
 * Never put the token itself in the message — this exception is rendered back
 * to the panel and written to the activity log.
 */
final class InvalidBotTokenException extends TelegramApiException
{
    public static function malformed(): self
    {
        return new self(
            message: 'The bot token is not in the expected <digits>:<secret> format.',
            errorCode: 401,
            method: 'validateToken',
        );
    }

    public static function rejected(?Throwable $previous = null): self
    {
        return new self(
            message: 'Telegram rejected the bot token.',
            errorCode: 401,
            method: 'getMe',
            previous: $previous,
        );
    }

    public function friendlyKey(): string
    {
        return 'telegram.connect_error.invalid_token';
    }
}
