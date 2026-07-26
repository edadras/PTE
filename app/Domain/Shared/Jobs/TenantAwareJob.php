<?php

declare(strict_types=1);

namespace App\Domain\Shared\Jobs;

use App\Domain\Shared\Jobs\Middleware\SetTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every queued job that touches tenant data.
 *
 * Two things matter here:
 *  - the tenant is re-established inside the worker (a job outlives the request
 *    that queued it, so TenantContext is empty by the time it runs);
 *  - jobs are tagged per academy, which is the only practical way to debug a
 *    single customer's queue once there are a hundred of them in Horizon.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $academyId) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SetTenantContext($this->academyId)];
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'academy:'.$this->academyId,
            class_basename(static::class),
        ];
    }
}
