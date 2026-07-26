<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Events\TicketAssigned;
use App\Domain\Support\Models\SupportTicket;
use App\Models\User;

final class AssignTicket
{
    public function handle(SupportTicket $ticket, User|int|null $assignee): SupportTicket
    {
        $assigneeId = $assignee instanceof User ? (int) $assignee->getKey() : $assignee;
        $previous = $ticket->assigned_to;

        if ($previous === $assigneeId) {
            return $ticket;
        }

        $ticket->forceFill(['assigned_to' => $assigneeId])->save();

        TicketAssigned::dispatch($ticket, $previous, $assigneeId);

        return $ticket;
    }
}
