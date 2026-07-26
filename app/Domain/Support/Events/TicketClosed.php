<?php

declare(strict_types=1);

namespace App\Domain\Support\Events;

use App\Domain\Support\Models\SupportTicket;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketClosed
{
    use Dispatchable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?int $closedBy,
        public readonly ?string $resolution = null,
    ) {}
}
