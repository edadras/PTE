<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\PlatformAuditLog;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Tenancy\Models\Academy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thin adapter between the platform panel and the Audit context.
 *
 * The panel never writes an audit row itself — AuditRecorder owns the table,
 * the redaction rules and the mirroring into the tenant's own trail. This class
 * only pins the actor (Filament actions run inside a request, but a queued
 * follow-up may not) and hands over.
 *
 * @see docs/02-roles-and-rbac.md §7
 */
final class PlatformAudit
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        AuditAction $action,
        ?User $actor = null,
        Academy|int|null $target = null,
        array $payload = [],
    ): void {
        $recorder = app(AuditRecorder::class);

        if ($actor instanceof User) {
            $recorder->actingAs($actor);
        }

        try {
            $recorder->recordPlatform($action, $target, $payload);
        } finally {
            $recorder->forgetActor();
        }
    }

    /**
     * @return Builder<PlatformAuditLog>
     */
    public static function query(): Builder
    {
        return PlatformAuditLog::query();
    }
}
