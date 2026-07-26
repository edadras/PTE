<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Integration\Support\ApiExceptionMapper;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` for POSTs that create a session or move money (docs/08 §4).
 *
 * The first successful response is cached for 24 hours under
 * academy + key + route, and replayed verbatim afterwards. A second request
 * that arrives while the first is still in flight gets 409 rather than a second
 * charge — silently queueing it would only move the double-submit later.
 *
 * The stored key includes the academy id explicitly even though the cache is
 * already tenant-prefixed: defence in depth against a prefix that was not set.
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotency-Replayed';

    private const TTL_SECONDS = 86400;

    /** How long one in-flight request may hold the key. */
    private const LOCK_SECONDS = 60;

    public function __construct(private readonly ApiExceptionMapper $mapper) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->headers->get(self::HEADER, ''));

        if ($key === '' || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (mb_strlen($key) > 191) {
            return $this->mapper->envelope(
                400,
                'IDEMPOTENCY_KEY_INVALID',
                __('api.errors.idempotency_key_invalid'),
                null,
                $request,
            );
        }

        $cacheKey = $this->cacheKey($request, $key);

        /** @var array{status: int, body: string}|null $stored */
        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return $this->replay($stored);
        }

        if (! Cache::add($cacheKey.':lock', true, self::LOCK_SECONDS)) {
            return $this->mapper->envelope(
                409,
                'IDEMPOTENCY_IN_PROGRESS',
                __('api.errors.idempotency_in_progress'),
                null,
                $request,
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
            ], self::TTL_SECONDS);
        }

        // A failed attempt must be retryable immediately with the same key.
        Cache::forget($cacheKey.':lock');

        return $response;
    }

    /**
     * @param  array{status: int, body: string}  $stored
     */
    private function replay(array $stored): JsonResponse
    {
        return JsonResponse::fromJsonString($stored['body'], $stored['status'], [
            self::REPLAY_HEADER => 'true',
        ]);
    }

    private function cacheKey(Request $request, string $key): string
    {
        return 'idem:'.hash('sha256', implode('|', [
            (string) TenantContext::idOrNull(),
            $request->route()?->getName() ?? $request->path(),
            $request->getMethod(),
            $key,
        ]));
    }
}
