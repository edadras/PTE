<?php

declare(strict_types=1);

namespace App\Domain\Support\Exceptions;

use RuntimeException;

final class TicketClosedException extends RuntimeException
{
    public static function forTicket(int $ticketId): self
    {
        return new self("Ticket [{$ticketId}] is closed and cannot take new replies.");
    }
}
