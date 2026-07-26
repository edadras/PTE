<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Tenancy\Events\AcademyCloned;
use App\Domain\Tenancy\Events\AcademyCreated;
use App\Domain\Tenancy\Events\AcademyDeleted;
use App\Domain\Tenancy\Events\AcademyResumed;
use Illuminate\Events\Dispatcher;

/**
 * Mandatory audit (docs/02 §7): the Super Admin lifecycle of a tenant.
 *
 * Written via recordPlatform so each entry lands in `platform_audit_logs`
 * *and* is mirrored into the academy's own trail — the customer must be able
 * to see what the platform did to them without platform access.
 */
final class RecordAcademyLifecycle
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(AcademyCreated::class, [self::class, 'handleCreated']);
        $events->listen(AcademyResumed::class, [self::class, 'handleResumed']);
        $events->listen(AcademyDeleted::class, [self::class, 'handleDeleted']);
        $events->listen(AcademyCloned::class, [self::class, 'handleCloned']);
    }

    public function handleCreated(AcademyCreated $event): void
    {
        $academy = $event->academy;

        $this->recorder->recordPlatform(AuditAction::AcademyCreated, $academy, [
            'name' => $academy->name,
            'slug' => $academy->slug,
            'status' => $academy->status,
        ]);
    }

    public function handleResumed(AcademyResumed $event): void
    {
        $academy = $event->academy;

        $this->recorder->recordPlatform(AuditAction::AcademyResumed, $academy, [
            'name' => $academy->name,
            'status' => $academy->status,
        ]);
    }

    public function handleDeleted(AcademyDeleted $event): void
    {
        $academy = $event->academy;

        $this->recorder->recordPlatform(AuditAction::AcademyDeleted, $academy, [
            'name' => $academy->name,
            'slug' => $academy->slug,
            'status' => $academy->status,
        ]);
    }

    public function handleCloned(AcademyCloned $event): void
    {
        $this->recorder->recordPlatform(AuditAction::AcademyCloned, $event->target, [
            'source_academy_id' => (int) $event->source->getKey(),
            'source_slug' => $event->source->slug,
            'target_slug' => $event->target->slug,
        ]);
    }
}
