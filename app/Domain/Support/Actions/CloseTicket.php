<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketSender;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Events\TicketClosed;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use Illuminate\Support\Facades\DB;

final class CloseTicket
{
    public function handle(SupportTicket $ticket, ?int $closedBy = null, ?string $resolution = null): SupportTicket
    {
        if ($ticket->isClosed()) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $closedBy, $resolution): void {
            $now = now();

            if ($resolution !== null && trim($resolution) !== '') {
                SupportTicketMessage::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'sender_type' => $closedBy === null ? TicketSender::System : TicketSender::Staff,
                    'sender_id' => $closedBy,
                    'content' => $resolution,
                    'is_internal' => false,
                    'created_at' => $now,
                ]);
            }

            $ticket->forceFill([
                'status' => TicketStatus::Closed,
                'closed_at' => $now,
                'closed_by' => $closedBy,
            ])->save();
        });

        TicketClosed::dispatch($ticket, $closedBy, $resolution);

        return $ticket;
    }
}
