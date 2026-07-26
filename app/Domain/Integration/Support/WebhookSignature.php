<?php

declare(strict_types=1);

namespace App\Domain\Integration\Support;

/**
 * `X-PTE-Signature: sha256=<hex>` — HMAC-SHA256 of the exact bytes we send.
 *
 * The signature covers the serialised body rather than the payload array, so a
 * receiver can verify without having to reproduce our JSON encoding.
 *
 * @see docs/08-api-and-integrations.md §5
 */
final class WebhookSignature
{
    public const HEADER = 'X-PTE-Signature';

    public const ALGORITHM = 'sha256';

    public static function sign(string $body, string $secret): string
    {
        return self::ALGORITHM.'='.hash_hmac(self::ALGORITHM, $body, $secret);
    }

    public static function verify(string $body, string $secret, string $provided): bool
    {
        return hash_equals(self::sign($body, $secret), trim($provided));
    }

    /** Secrets are shown once, at creation, exactly like an API key. */
    public static function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }
}
