<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\Shared\Support\TenantKey;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Content-hash cache of identical calls — worth 10–15% of AI spend in practice
 * (docs/06 §7.3), because a class of thirty students answers the same Read Aloud
 * item the same way more often than one would hope.
 *
 * Entries are namespaced per academy: a cached answer is derived from that
 * academy's prompt and rubric, and sharing it across tenants would leak both.
 */
final class PromptCache
{
    public function __construct(private readonly ?Repository $cache = null) {}

    public function get(AiCompletionRequest $request): ?AiCompletionResponse
    {
        if ($request->academyId === null) {
            return null;
        }

        $payload = $this->store()->get($this->key($request));

        if (! is_array($payload)) {
            return null;
        }

        return AiCompletionResponse::fromArray($payload)->withCacheHit();
    }

    public function put(AiCompletionRequest $request, AiCompletionResponse $response): void
    {
        // Never cache a response we could not parse — a cached malformed answer
        // would poison every identical submission for a week.
        if ($request->academyId === null || $response->parsedJson === null) {
            return;
        }

        $this->store()->put($this->key($request), $response->toArray(), $this->ttlSeconds());
    }

    public function forget(AiCompletionRequest $request): void
    {
        if ($request->academyId !== null) {
            $this->store()->forget($this->key($request));
        }
    }

    public function key(AiCompletionRequest $request): string
    {
        return TenantKey::for(
            (int) $request->academyId,
            'ai:cache',
            $request->task->value,
            $request->contentHash(),
        );
    }

    private function ttlSeconds(): int
    {
        return (int) config('pte.ai.cache_ttl_days', 7) * 86400;
    }

    private function store(): Repository
    {
        return $this->cache ?? Cache::store();
    }
}
