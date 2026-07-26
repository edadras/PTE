<?php

declare(strict_types=1);

namespace App\Domain\Shared\Jobs\Middleware;

use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware that resolves the academy before the job body runs and clears
 * it afterwards, so a worker process never carries one tenant's context into
 * the next job it picks up.
 */
final class SetTenantContext
{
    public function __construct(private readonly int $academyId) {}

    public function handle(object $job, Closure $next): mixed
    {
        $academy = Academy::query()->withoutGlobalScopes()->find($this->academyId);

        if (! $academy instanceof Academy) {
            Log::warning('Dropping job for a missing academy.', [
                'academy_id' => $this->academyId,
                'job' => $job::class,
            ]);

            return null;
        }

        try {
            TenantContext::set($academy);

            return $next($job);
        } finally {
            TenantContext::forget();
        }
    }
}
