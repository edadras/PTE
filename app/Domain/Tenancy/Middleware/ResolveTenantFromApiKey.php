<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Identity\Models\ApiKey;
use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API entry point: `Authorization: Bearer pte_live_ak_…` -> academy.
 *
 * Only the SHA-256 hash is ever compared, and the lookup deliberately bypasses
 * the tenant scope — this query is how the tenant gets resolved in the first
 * place, so it cannot itself be tenant-scoped.
 *
 * @see docs/01-multi-tenancy.md §4.3
 */
final class ResolveTenantFromApiKey
{
    /** Do not write `last_used_at` more often than this (seconds). */
    private const USAGE_WRITE_INTERVAL = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        abort_if($token === null, 401, 'API key missing.');

        $apiKey = ApiKey::query()
            ->withoutGlobalScope('academy')
            ->usable()
            ->where('token_hash', ApiKey::hashToken($token))
            ->first();

        abort_if(! $apiKey instanceof ApiKey, 401, 'Invalid API key.');

        $academy = Academy::query()->whereKey($apiKey->academy_id)->first();

        abort_if(! $academy instanceof Academy, 401, 'Invalid API key.');
        abort_if($academy->status === AcademyStatus::Deleted, 403, 'Academy is not available.');

        TenantContext::set($academy);

        $this->recordUsage($apiKey);

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('academy', $academy);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            $token = $request->header('X-Api-Key');
        }

        return blank($token) ? null : trim((string) $token);
    }

    private function recordUsage(ApiKey $apiKey): void
    {
        $last = $apiKey->last_used_at;

        if ($last !== null && $last->diffInSeconds(now()) < self::USAGE_WRITE_INTERVAL) {
            return;
        }

        $apiKey->markUsed();
    }
}
