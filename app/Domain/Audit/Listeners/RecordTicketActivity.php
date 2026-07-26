<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Support\Events\TicketAssigned;
use App\Domain\Support\Events\TicketClosed;
use App\Domain\Support\Events\TicketOpened;
use App\Domain\Support\Events\TicketReplied;
use Illuminate\Events\Dispatcher;

/**
 * Support conversations are auditable because staff read student data inside
 * them; who answered which ticket, and when, is the trail that makes that
 * defensible.
 *
 * Message bodies are not copied into the audit row — they already live in
 * `support_ticket_messages`, and duplicating student text doubles the surface
 * that has to be erased on a deletion request (docs/12).
 */
final class RecordTicketActivity
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(TicketOpened::class, [self::class, 'handleOpened']);
        $events->listen(TicketReplied::class, [self::class, 'handleReplied']);
        $events->listen(TicketClosed::class, [self::class, 'handleClosed']);
        $events->listen(TicketAssigned::class, [self::class, 'handleAssigned']);
    }

    public function handleOpened(TicketOpened $event): void
    {
        $this->recorder->record(
            AuditAction::TicketOpened,
            $event->ticket,
            [],
            [
                'subject' => $event->ticket->subject,
                'student_id' => $event->ticket->student_id,
                'priority' => $event->ticket->priority->value,
                'source' => $event->ticket->source->value,
            ],
            (int) $event->ticket->academy_id,
        );
    }

    public function handleReplied(TicketReplied $event): void
    {
        $this->recorder->record(
            AuditAction::TicketReplied,
            $event->ticket,
            [],
            [
                'message_id' => $event->message->getKey(),
                'from_staff' => $event->fromStaff,
                'internal' => $event->message->is_internal,
                'sender_id' => $event->message->sender_id,
            ],
            (int) $event->ticket->academy_id,
        );
    }

    public function handleClosed(TicketClosed $event): void
    {
        $this->recorder->record(
            AuditAction::TicketClosed,
            $event->ticket,
            [],
            ['closed_by' => $event->closedBy],
            (int) $event->ticket->academy_id,
        );
    }

    public function handleAssigned(TicketAssigned $event): void
    {
        $this->recorder->record(
            AuditAction::TicketAssigned,
            $event->ticket,
            ['assigned_to' => $event->previousAssignee],
            ['assigned_to' => $event->assignee],
            (int) $event->ticket->academy_id,
        );
    }
}
