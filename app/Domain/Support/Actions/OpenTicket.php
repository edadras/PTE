<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Data\OpenTicketData;
use App\Domain\Support\Enums\TicketSender;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Events\TicketOpened;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A ticket is never empty: the opening message is written in the same
 * transaction, so a support queue can never show a subject with nothing behind
 * it for an agent to answer.
 */
final class OpenTicket
{
    public function handle(OpenTicketData $data): SupportTicket
    {
        return DB::transaction(function () use ($data): SupportTicket {
            $now = now();

            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()->create([
                'student_id' => $data->studentId,
                'subject' => Str::limit(trim($data->subject) !== '' ? trim($data->subject) : $data->message, 180, ''),
                'status' => TicketStatus::Open,
                'priority' => $data->priority,
                'source' => $data->source,
                'assigned_to' => $data->assignedTo,
                'last_reply_at' => $now,
                'meta' => $data->meta,
            ]);

            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sender_type' => $data->studentId === null ? TicketSender::System : TicketSender::Student,
                'sender_id' => $data->studentId,
                'content' => $data->message,
                'attachments' => $data->attachments,
                'is_internal' => false,
                'created_at' => $now,
            ]);

            $ticket->setRelation('messages', collect([$message]));

            TicketOpened::dispatch($ticket, $message);

            return $ticket;
        });
    }
}
