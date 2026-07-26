<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

/**
 * Last line of defence against a bot token reaching a log file, a Sentry event
 * or an exception rendered in the browser.
 *
 * Layers one and two are the encrypted cast and $hidden on TelegramBot; this is
 * layer three and assumes the other two already failed.
 *
 * @see docs/12-security-and-compliance.md §3
 */
final class TokenRedactor
{
    public const PATTERN = '/\d{8,10}:[A-Za-z0-9_-]{35}/';

    public const REPLACEMENT = '[REDACTED_BOT_TOKEN]';

    public static function redact(string $value): string
    {
        return (string) preg_replace(self::PATTERN, self::REPLACEMENT, $value);
    }

    /**
     * Walk a log context / Sentry payload and redact every string leaf.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = match (true) {
                is_string($value) => self::redact($value),
                is_array($value) => self::redactArray($value),
                default => $value,
            };
        }

        return $context;
    }

    public static function contains(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /** The `••••••wEN` form shown in the panel. */
    public static function mask(?string $last4): string
    {
        return '••••••'.($last4 ?? '????');
    }
}
