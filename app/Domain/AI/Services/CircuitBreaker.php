<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Enums\AiProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a limping provider out of the chain.
 *
 * Failure ratio over a rolling window rather than a raw count, because a busy
 * academy and a quiet one must trip on the same *health*, not on the same
 * traffic. Counters are platform-wide on purpose: an outage at a vendor is not
 * a tenant-specific fact (docs/06 §8).
 */
final class CircuitBreaker
{
    private const PREFIX = 'ai:cb:';

    public function __construct(private readonly ?Repository $cache = null) {}

    public function allows(AiProvider $provider, string $modelKey): bool
    {
        return ! $this->isOpen($provider, $modelKey);
    }

    public function isOpen(AiProvider $provider, string $modelKey): bool
    {
        return (bool) $this->store()->get($this->key($provider, $modelKey, 'open'), false);
    }

    public function recordSuccess(AiProvider $provider, string $modelKey): void
    {
        $this->bump($provider, $modelKey, failed: false);
    }

    public function recordFailure(AiProvider $provider, string $modelKey): void
    {
        $this->bump($provider, $modelKey, failed: true);
    }

    public function reset(AiProvider $provider, string $modelKey): void
    {
        foreach (['open', 'total', 'fail'] as $suffix) {
            $this->store()->forget($this->key($provider, $modelKey, $suffix));
        }
    }

    /**
     * @return array{total: int, failures: int, ratio: float, open: bool}
     */
    public function status(AiProvider $provider, string $modelKey): array
    {
        $total = (int) $this->store()->get($this->key($provider, $modelKey, 'total'), 0);
        $failures = (int) $this->store()->get($this->key($provider, $modelKey, 'fail'), 0);

        return [
            'total' => $total,
            'failures' => $failures,
            'ratio' => $total > 0 ? round($failures / $total, 4) : 0.0,
            'open' => $this->isOpen($provider, $modelKey),
        ];
    }

    private function bump(AiProvider $provider, string $modelKey, bool $failed): void
    {
        $window = (int) config('pte.ai.circuit_breaker.window_seconds', 300);

        $total = $this->increment($this->key($provider, $modelKey, 'total'), $window);
        $failures = $failed
            ? $this->increment($this->key($provider, $modelKey, 'fail'), $window)
            : (int) $this->store()->get($this->key($provider, $modelKey, 'fail'), 0);

        $minSamples = (int) config('pte.ai.circuit_breaker.min_samples', 5);
        $threshold = (float) config('pte.ai.circuit_breaker.failure_ratio', 0.3);

        if ($total < $minSamples || $total === 0) {
            return;
        }

        if (($failures / $total) > $threshold) {
            $this->store()->put(
                $this->key($provider, $modelKey, 'open'),
                true,
                (int) config('pte.ai.circuit_breaker.open_seconds', 300),
            );
        }
    }

    /**
     * Cache::increment() does not set a TTL, so the counter is seeded with add()
     * first — otherwise a window counter would live forever and the breaker
     * would never forgive a provider.
     */
    private function increment(string $key, int $ttl): int
    {
        if ($this->store()->add($key, 1, $ttl)) {
            return 1;
        }

        $value = $this->store()->increment($key);

        return is_int($value) ? $value : 1;
    }

    private function key(AiProvider $provider, string $modelKey, string $suffix): string
    {
        return self::PREFIX.$provider->value.':'.$modelKey.':'.$suffix;
    }

    private function store(): Repository
    {
        return $this->cache ?? Cache::store();
    }
}
