<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Models\AcademyDomain;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel / web entry point: Host header -> academy.
 *
 * An unknown or unusable hostname is a 404, never a redirect to the platform:
 * the existence of the platform behind the white label is not advertised.
 *
 * @see docs/01-multi-tenancy.md §4.2
 */
final class ResolveTenantFromDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $hostname = AcademyDomain::normalizeHostname($request->getHost());

        $academyId = $this->academyIdFor($hostname);

        abort_if($academyId === null, 404);

        $academy = Academy::query()->whereKey($academyId)->first();

        abort_if(! $academy instanceof Academy, 404);
        abort_if($academy->status === AcademyStatus::Deleted, 404);

        TenantContext::set($academy);

        $request->attributes->set('academy', $academy);
        $request->attributes->set('academy_hostname', $hostname);

        return $next($request);
    }

    /**
     * The mapping is read on every single request, so it is cached; the cache
     * is written before any tenant is active, hence the explicit global key.
     */
    private function academyIdFor(string $hostname): ?int
    {
        $ttl = (int) config('pte.tenancy.cache_ttl', 3600);

        /** @var array{academy_id: int, usable: bool}|null $resolved */
        $resolved = Cache::remember(
            self::cacheKey($hostname),
            $ttl,
            static function () use ($hostname): ?array {
                $domain = AcademyDomain::query()->forHostname($hostname)->first();

                if (! $domain instanceof AcademyDomain) {
                    return null;
                }

                return [
                    'academy_id' => (int) $domain->academy_id,
                    'usable' => $domain->isUsable(),
                ];
            }
        );

        if ($resolved === null || $resolved['usable'] === false) {
            return null;
        }

        return $resolved['academy_id'];
    }

    public static function cacheKey(string $hostname): string
    {
        return 'tenant:domain:'.AcademyDomain::normalizeHostname($hostname);
    }

    public static function forget(string $hostname): void
    {
        Cache::forget(self::cacheKey($hostname));
    }
}
