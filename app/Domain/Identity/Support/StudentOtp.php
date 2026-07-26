<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\Student;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The "log in to the web" code a student asks the bot for.
 *
 * Only a hash of the code is stored, so a cache dump is not a set of live
 * credentials, and a five-attempt cap turns a 6-digit space (10^6) into
 * something no online guesser can walk.
 *
 * @see docs/08-api-and-integrations.md §2
 */
final class StudentOtp
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    private const LENGTH = 6;

    /** @return string the plaintext code — shown to the student exactly once */
    public static function issue(Student $student): string
    {
        $code = str_pad((string) random_int(0, 999999), self::LENGTH, '0', STR_PAD_LEFT);

        Cache::put(self::key((int) $student->getKey()), [
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * @return bool true only for a live, correct, not-yet-exhausted code
     */
    public static function verify(Student $student, string $code): bool
    {
        $key = self::key((int) $student->getKey());

        /** @var array{hash: string, attempts: int}|null $record */
        $record = Cache::get($key);

        if ($record === null) {
            return false;
        }

        if ($record['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return false;
        }

        if (! Hash::check(trim($code), $record['hash'])) {
            Cache::put(
                $key,
                ['hash' => $record['hash'], 'attempts' => $record['attempts'] + 1],
                self::TTL_SECONDS,
            );

            return false;
        }

        // Single use: a correct code is burnt whether or not the caller
        // succeeds at whatever it does next.
        Cache::forget($key);

        return true;
    }

    public static function forget(Student $student): void
    {
        Cache::forget(self::key((int) $student->getKey()));
    }

    private static function key(int $studentId): string
    {
        return TenantKey::make('otp:student', $studentId);
    }
}
