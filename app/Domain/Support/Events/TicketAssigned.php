<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Support\Models\SupportTicket;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?int $previousAssignee,
        public readonly ?int $assignee,
    ) {}
}
