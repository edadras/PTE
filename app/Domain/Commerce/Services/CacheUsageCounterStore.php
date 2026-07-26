<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Contracts\UsageCounterStore;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-store counters for environments without Redis (tests, single-node
 * installs). Atomicity depends on the underlying driver; the array driver used
 * in tests is single-process, so it is exact there.
 */
final class CacheUsageCounterStore implements UsageCounterStore
{
    public function __construct(private readonly ?string $store = null) {}

    public function get(int $academyId, string $metric, string $period): int
    {
        return (int) $this->cache()->get($this->key($academyId, $metric, $period), 0);
    }

    public function increment(int $academyId, string $metric, string $period, int $amount, int $ttlSeconds): int
    {
        $key = $this->key($academyId, $metric, $period);
        $cache = $this->cache();

        // add() is the atomic "create if absent" that lets increment() below be
        // safe without a race on the first write of the period.
        $cache->add($key, 0, $ttlSeconds);

        return (int) $cache->increment($key, $amount);
    }

    public function decrement(int $academyId, string $metric, string $period, int $amount): int
    {
        $key = $this->key($academyId, $metric, $period);
        $value = (int) $this->cache()->decrement($key, $amount);

        if ($value < 0) {
            $this->cache()->forever($key, 0);

            return 0;
        }

        return $value;
    }

    public function put(int $academyId, string $metric, string $period, int $value, int $ttlSeconds): void
    {
        $this->cache()->put($this->key($academyId, $metric, $period), $value, $ttlSeconds);
    }

    public function forget(int $academyId, string $metric, string $period): void
    {
        $this->cache()->forget($this->key($academyId, $metric, $period));
    }

    private function cache(): Repository
    {
        return Cache::store($this->store);
    }

    /**
     * Bypasses the tenant cache prefix on purpose: the key already carries the
     * academy id, and quota roll-ups run outside any tenant context.
     */
    private function key(int $academyId, string $metric, string $period): string
    {
        return 'quota:'.TenantKey::quota($academyId, $metric, $period);
    }
}
