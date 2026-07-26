<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Domain\Support\Enums\TicketSender;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Events\TicketReplied;
use App\Domain\Support\Exceptions\TicketClosedException;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * Add a turn to a ticket and move its status the way the sender implies:
 * a staff reply puts the ticket on the student ("pending"), a student reply
 * puts it back on the queue ("open").
 *
 * Getting that automatic is what keeps a support queue honest — an agent who
 * has to remember to set a status will forget, and answered tickets pile up
 * looking unanswered.
 */
final class ReplyToTicket
{
    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function handle(
        SupportTicket $ticket,
        string $content,
        TicketSender $sender,
        ?int $senderId = null,
        bool $isInternal = false,
        array $attachments = [],
    ): SupportTicketMessage {
        if ($ticket->isClosed()) {
            throw TicketClosedException::forTicket((int) $ticket->getKey());
        }

        $message = DB::transaction(function () use ($ticket, $content, $sender, $senderId, $isInternal, $attachments): SupportTicketMessage {
            $now = now();

            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'sender_type' => $sender,
                'sender_id' => $senderId,
                'content' => $content,
                'attachments' => $attachments,
                'is_internal' => $isInternal,
                'created_at' => $now,
            ]);

            // An internal note is a staff memo, not a reply — it must not make
            // the ticket look like the student has been answered.
            if (! $isInternal) {
                $ticket->forceFill([
                    'last_reply_at' => $now,
                    'status' => $sender->isStaff() ? TicketStatus::Pending : TicketStatus::Open,
                ])->save();
            }

            return $message;
        });

        TicketReplied::dispatch(
            $ticket,
            $message,
            $sender->isStaff(),
            $sender->isStaff() && ! $isInternal && $ticket->student_id !== null,
        );

        return $message;
    }
}
