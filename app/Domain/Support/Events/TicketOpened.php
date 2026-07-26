<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketOpened
{
    use Dispatchable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
    ) {}
}
