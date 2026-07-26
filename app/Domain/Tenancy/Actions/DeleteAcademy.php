<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\AcademyStatus;
use App\Domain\Tenancy\Events\AcademyDeleted;
use App\Domain\Tenancy\Middleware\ResolveTenantFromDomain;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\Services\BrandResolver;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletion, as specified in docs/01 §8: rows survive the 30 day retention
 * window, but the tenant stops being reachable immediately — every hostname
 * cache entry is dropped so the next request 404s.
 *
 * Purging storage, Redis keys and Telegram webhooks is the job of the
 * retention command in the Ops context, which owns the tombstone log.
 *
 * @see docs/01-multi-tenancy.md §8
 */
final class DeleteAcademy
{
    public function __construct(private readonly BrandResolver $brandResolver) {}

    public function handle(Academy $academy): Academy
    {
        DB::transaction(function () use ($academy): void {
            $academy->forceFill(['status' => AcademyStatus::Deleted])->save();

            foreach ($academy->domains as $domain) {
                ResolveTenantFromDomain::forget($domain->hostname);
            }

            $academy->delete();
        });

        $this->brandResolver->forget($academy);

        $academy->refresh();

        // Mandatory audit hook (docs/02 §7); fired after commit, while the
        // soft-deleted row is still inside its retention window.
        AcademyDeleted::dispatch($academy);

        return $academy;
    }
}
