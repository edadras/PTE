<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Student;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Validates a Telegram Mini App `initData` string and resolves it to a student.
 *
 * The algorithm is Telegram's: the check string is every field except `hash`,
 * sorted by key, joined with newlines; the key is
 * `HMAC_SHA256("WebAppData", bot_token)`; the comparison is constant time.
 *
 * `auth_date` older than 24 hours is rejected — otherwise a captured initData
 * string would be a permanent credential.
 *
 * @see docs/08-api-and-integrations.md §2 · docs/04-telegram-layer.md §3
 */
final class AuthenticateTelegramInitData
{
    public const MAX_AGE_SECONDS = 86400;

    private const SECRET_SALT = 'WebAppData';

    /**
     * @return array{student: Student, identity: TelegramIdentity, telegram_user: array<string, mixed>}
     *
     * @throws ValidationException when the signature is invalid or stale
     * @throws AuthorizationException when the Telegram user is not a known student
     */
    public function handle(string $initData, ?TelegramBot $bot = null): array
    {
        $academy = TenantContext::require();

        $bot ??= TelegramBot::query()->active()->first();

        if (! $bot instanceof TelegramBot || blank($bot->token)) {
            throw ValidationException::withMessages([
                'init_data' => __('api.auth.telegram_bot_missing'),
            ]);
        }

        $fields = $this->verified($initData, (string) $bot->token);

        $user = $this->decodeUser($fields);

        $identity = TelegramIdentity::query()
            ->where('telegram_user_id', (int) $user['id'])
            ->first();

        if (! $identity instanceof TelegramIdentity || $identity->student_id === null) {
            throw new AuthorizationException(__('api.auth.telegram_not_linked'));
        }

        $student = Student::query()->find($identity->student_id);

        // The tenant scope already constrains this query; the explicit check is
        // the belt to that braces — a token is never issued across academies.
        if (! $student instanceof Student || (int) $student->academy_id !== (int) $academy->getKey()) {
            throw new AuthorizationException(__('api.auth.telegram_not_linked'));
        }

        $identity->touchInteraction();

        return ['student' => $student, 'identity' => $identity, 'telegram_user' => $user];
    }

    /**
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function verified(string $initData, string $botToken): array
    {
        parse_str($initData, $parsed);

        /** @var array<string, mixed> $parsed */
        $hash = $parsed['hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            throw ValidationException::withMessages(['init_data' => __('api.auth.init_data_invalid')]);
        }

        unset($parsed['hash'], $parsed['signature']);

        $fields = [];

        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $fields[(string) $key] = $value;
            }
        }

        ksort($fields);

        $checkString = implode("\n", array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($fields),
            array_values($fields),
        ));

        $secretKey = hash_hmac('sha256', $botToken, self::SECRET_SALT, binary: true);
        $expected = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($expected, strtolower($hash))) {
            throw ValidationException::withMessages(['init_data' => __('api.auth.init_data_invalid')]);
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate <= 0 || (now()->getTimestamp() - $authDate) > self::MAX_AGE_SECONDS) {
            throw ValidationException::withMessages(['init_data' => __('api.auth.init_data_expired')]);
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function decodeUser(array $fields): array
    {
        $decoded = json_decode($fields['user'] ?? '', true);

        if (! is_array($decoded) || ! isset($decoded['id'])) {
            throw ValidationException::withMessages(['init_data' => __('api.auth.init_data_invalid')]);
        }

        return $decoded;
    }
}
