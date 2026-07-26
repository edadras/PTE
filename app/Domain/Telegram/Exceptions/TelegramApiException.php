<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use App\Domain\Telegram\Support\TokenRedactor;
use RuntimeException;
use Throwable;

/**
 * A non-ok response from the Bot API.
 *
 * Carries the two fields every caller branches on: `error_code` and, for 429s,
 * `retry_after` from `parameters`. The message is redacted on construction —
 * Telegram echoes the request URL (which contains the token) in some error
 * bodies, and this exception ends up in logs and Horizon.
 */
class TelegramApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
        public readonly string $method = '',
        public readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(TokenRedactor::redact($message), $errorCode ?? 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(string $method, array $response, ?Throwable $previous = null): self
    {
        $code = isset($response['error_code']) ? (int) $response['error_code'] : null;
        $retryAfter = isset($response['parameters']['retry_after'])
            ? (int) $response['parameters']['retry_after']
            : null;

        $description = is_string($response['description'] ?? null)
            ? $response['description']
            : 'Telegram API call failed.';

        return new self(
            message: "[{$method}] {$description}",
            errorCode: $code,
            retryAfter: $retryAfter,
            method: $method,
            response: $response,
            previous: $previous,
        );
    }

    public static function transport(string $method, Throwable $previous): self
    {
        return new self(
            message: "[{$method}] Could not reach the Telegram API: ".$previous->getMessage(),
            errorCode: null,
            retryAfter: null,
            method: $method,
            response: [],
            previous: $previous,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->errorCode === 429;
    }

    /** The user blocked the bot, or the bot was kicked. */
    public function isForbidden(): bool
    {
        return $this->errorCode === 403;
    }

    public function isUnauthorized(): bool
    {
        return $this->errorCode === 401;
    }

    public function isChatNotFound(): bool
    {
        return $this->errorCode === 400
            && str_contains(mb_strtolower($this->getMessage()), 'chat not found');
    }

    /** Transport failures (no error_code) count as retryable too. */
    public function isServerError(): bool
    {
        return $this->errorCode === null || $this->errorCode >= 500;
    }

    public function isRetryable(): bool
    {
        return $this->isRateLimited() || $this->isServerError();
    }

    /** Translation key for the message shown to the academy owner. */
    public function friendlyKey(): string
    {
        return match (true) {
            $this->isUnauthorized() => 'telegram.connect_error.invalid_token',
            $this->isRateLimited() => 'telegram.connect_error.rate_limited',
            $this->isServerError() => 'telegram.connect_error.temporary',
            default => 'telegram.connect_error.generic',
        };
    }
}
