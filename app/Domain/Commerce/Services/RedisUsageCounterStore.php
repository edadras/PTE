<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Contracts\UsageCounterStore;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed quota counters.
 *
 * INCRBY is atomic, which is the whole point: two concurrent AI requests must
 * not both read "999 of 1000" and both proceed.
 *
 * Every operation falls back to the cache store when Redis is unreachable —
 * losing a quota counter must not take down scoring (docs/09 §2).
 */
final class RedisUsageCounterStore implements UsageCounterStore
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly UsageCounterStore $fallback = new CacheUsageCounterStore,
    ) {}

    public function get(int $academyId, string $metric, string $period): int
    {
        try {
            return (int) (Redis::connection($this->connection)->get($this->key($academyId, $metric, $period)) ?? 0);
        } catch (Throwable) {
            return $this->fallback->get($academyId, $metric, $period);
        }
    }

    public function increment(int $academyId, string $metric, string $period, int $amount, int $ttlSeconds): int
    {
        $key = $this->key($academyId, $metric, $period);

        try {
            $connection = Redis::connection($this->connection);
            $value = (int) $connection->incrby($key, $amount);

            // Only set the TTL on creation, otherwise a busy month keeps
            // pushing expiry forward and the key never dies.
            if ($value === $amount) {
                $connection->expire($key, $ttlSeconds);
            }

            return $value;
        } catch (Throwable) {
            return $this->fallback->increment($academyId, $metric, $period, $amount, $ttlSeconds);
        }
    }

    public function decrement(int $academyId, string $metric, string $period, int $amount): int
    {
        try {
            $value = (int) Redis::connection($this->connection)->decrby($this->key($academyId, $metric, $period), $amount);

            return max(0, $value);
        } catch (Throwable) {
            return $this->fallback->decrement($academyId, $metric, $period, $amount);
        }
    }

    public function put(int $academyId, string $metric, string $period, int $value, int $ttlSeconds): void
    {
        try {
            Redis::connection($this->connection)->setex($this->key($academyId, $metric, $period), $ttlSeconds, $value);
        } catch (Throwable) {
            $this->fallback->put($academyId, $metric, $period, $value, $ttlSeconds);
        }
    }

    public function forget(int $academyId, string $metric, string $period): void
    {
        try {
            Redis::connection($this->connection)->del($this->key($academyId, $metric, $period));
        } catch (Throwable) {
            $this->fallback->forget($academyId, $metric, $period);
        }
    }

    private function key(int $academyId, string $metric, string $period): string
    {
        return TenantKey::quota($academyId, $metric, $period);
    }
}
