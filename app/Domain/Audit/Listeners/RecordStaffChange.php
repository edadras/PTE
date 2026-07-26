<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Events\RoleChanged;
use App\Domain\Identity\Events\StaffInvited;
use App\Domain\Identity\Events\StaffRemoved;
use Illuminate\Events\Dispatcher;

/**
 * Mandatory audit (docs/02 §7): who was let into the academy, with which role,
 * and who was shown the door. This is the trail a "how did that account get
 * manager rights" question is answered from.
 */
final class RecordStaffChange
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(StaffInvited::class, [self::class, 'handleInvited']);
        $events->listen(StaffRemoved::class, [self::class, 'handleRemoved']);
        $events->listen(RoleChanged::class, [self::class, 'handleRoleChanged']);
    }

    public function handleInvited(StaffInvited $event): void
    {
        $membership = $event->membership;

        $this->recorder->record(
            AuditAction::StaffInvited,
            $membership,
            [],
            [
                'user_id' => $membership->user_id,
                'role' => $membership->role?->name,
                'status' => $membership->status,
                'invited_by' => $membership->invited_by,
            ],
            (int) $membership->academy_id,
        );
    }

    public function handleRemoved(StaffRemoved $event): void
    {
        $this->recorder->record(
            AuditAction::StaffRemoved,
            $event->user,
            ['roles' => $event->roleNames],
            [
                'user_id' => (int) $event->user->getKey(),
                'removed_by' => $event->removedBy,
            ],
            (int) $event->academy->getKey(),
        );
    }

    public function handleRoleChanged(RoleChanged $event): void
    {
        $membership = $event->membership;

        $this->recorder->record(
            AuditAction::RoleChanged,
            $membership,
            [],
            [
                'user_id' => $membership->user_id,
                'role' => $membership->role?->name,
                'status' => $membership->status,
            ],
            (int) $membership->academy_id,
        );
    }
}
