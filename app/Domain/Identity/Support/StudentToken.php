<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\Student;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stateless bearer token for the Student API.
 *
 * Sanctum was the design intent (docs/08 §2) but `Student` is not an
 * Authenticatable and does not use `HasApiTokens`, so a personal access token
 * cannot be minted for one. Rather than change a domain model that the bot and
 * the panel already depend on, the token is a signed structure of its own:
 *
 *     pte_st_<base64url(payload)>.<base64url(hmac)>
 *     payload = "<student_id>|<academy_id>|<expires_at>|<nonce>"
 *
 * Properties that matter:
 *  - HMAC-SHA256 keyed on APP_KEY, so a token cannot be forged without it;
 *  - constant-time comparison, so the signature cannot be discovered by timing;
 *  - `aid` is carried but never trusted (docs/08 §2) — StudentTokenGuard
 *    re-resolves the tenant from the host and compares.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class StudentToken
{
    public const PREFIX = 'pte_st_';

    public const TYPE = 'student';

    /** Long enough for a mobile session, short enough that revocation matters. */
    public const DEFAULT_TTL_MINUTES = 60 * 24 * 30;

    private function __construct(
        public readonly int $studentId,
        public readonly int $academyId,
        public readonly int $expiresAt,
    ) {}

    public static function issue(Student $student, ?int $ttlMinutes = null): string
    {
        $ttl = $ttlMinutes ?? self::DEFAULT_TTL_MINUTES;

        $payload = implode('|', [
            (int) $student->getKey(),
            (int) $student->academy_id,
            now()->addMinutes($ttl)->getTimestamp(),
            Str::random(16),
        ]);

        return self::PREFIX
            .self::encode($payload)
            .'.'
            .self::encode(self::signature($payload));
    }

    /** Null for anything malformed, tampered with or expired — never an exception. */
    public static function parse(string $token): ?self
    {
        $token = trim($token);

        if (! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $parts = explode('.', substr($token, strlen(self::PREFIX)), 2);

        if (count($parts) !== 2) {
            return null;
        }

        $payload = self::decode($parts[0]);
        $signature = self::decode($parts[1]);

        if ($payload === null || $signature === null) {
            return null;
        }

        if (! hash_equals(self::signature($payload), $signature)) {
            return null;
        }

        $fields = explode('|', $payload);

        if (count($fields) !== 4) {
            return null;
        }

        $expiresAt = (int) $fields[2];

        if ($expiresAt <= now()->getTimestamp()) {
            return null;
        }

        return new self((int) $fields[0], (int) $fields[1], $expiresAt);
    }

    public function expiresInSeconds(): int
    {
        return max(0, $this->expiresAt - now()->getTimestamp());
    }

    private static function signature(string $payload): string
    {
        return hash_hmac('sha256', self::TYPE.':'.$payload, self::key(), binary: true);
    }

    private static function key(): string
    {
        $key = (string) Config::get('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY must be set before student tokens can be issued.');
        }

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
