<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Contracts;

/**
 * The hot counter behind QuotaGuard.
 *
 * Redis in production; the cache repository in tests and on installations
 * without Redis. Quotas must degrade gracefully, so a missing counter store is
 * never allowed to take the application down.
 */
interface UsageCounterStore
{
    public function get(int $academyId, string $metric, string $period): int;

    /** @return int The value after the increment. */
    public function increment(int $academyId, string $metric, string $period, int $amount, int $ttlSeconds): int;

    /** @return int The value after the decrement, floored at zero. */
    public function decrement(int $academyId, string $metric, string $period, int $amount): int;

    public function put(int $academyId, string $metric, string $period, int $value, int $ttlSeconds): void;

    public function forget(int $academyId, string $metric, string $period): void;
}
