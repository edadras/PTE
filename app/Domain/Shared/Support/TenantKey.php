<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use App\Domain\Tenancy\TenantContext;

/**
 * Builds Redis keys that are always namespaced by academy.
 *
 * Laravel's cache prefix handles Cache::* automatically, but anything that
 * reaches for Redis directly (conversation state, rate limiters, quota
 * counters) must go through here — an unprefixed key is a cross-tenant leak.
 */
final class TenantKey
{
    /**
     * @param  scalar  ...$parts
     */
    public static function make(string $namespace, int|string ...$parts): string
    {
        return self::for(TenantContext::id(), $namespace, ...$parts);
    }

    /**
     * @param  scalar  ...$parts
     */
    public static function for(int $academyId, string $namespace, int|string ...$parts): string
    {
        $key = "ac{$academyId}:{$namespace}";

        foreach ($parts as $part) {
            $key .= ':'.$part;
        }

        return $key;
    }

    public static function conversationState(int $academyId, int|string $chatId): string
    {
        return self::for($academyId, 'tg:state', $chatId);
    }

    public static function callbackPayload(int $academyId, string $token): string
    {
        return self::for($academyId, 'tg:cb', $token);
    }

    public static function updateSeen(int $academyId, int $updateId): string
    {
        return self::for($academyId, 'tg:upd', $updateId);
    }

    public static function quota(int $academyId, string $metric, string $period): string
    {
        return self::for($academyId, 'quota', $metric, $period);
    }
}
