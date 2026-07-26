<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new turn was added to a ticket.
 *
 * The Telegram layer listens for this to push a staff reply back to the student
 * — which is why `fromStaff` is carried explicitly rather than re-derived: an
 * internal note must never reach the student, and a listener that has to work
 * that out for itself will eventually get it wrong.
 */
final class TicketReplied
{
    use Dispatchable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
        public readonly bool $fromStaff,
        public readonly bool $notifiesStudent,
    ) {}
}
