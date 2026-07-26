<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\OpsServiceProvider;
use App\Domain\Identity\Models\Student;
use App\Domain\Support\Actions\AssignTicket;
use App\Domain\Support\Actions\CloseTicket;
use App\Domain\Support\Actions\OpenTicket;
use App\Domain\Support\Actions\ReplyToTicket;
use App\Domain\Support\Data\OpenTicketData;
use App\Domain\Support\Enums\TicketPriority;
use App\Domain\Support\Enums\TicketSender;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Events\TicketReplied;
use App\Domain\Support\Exceptions\TicketClosedException;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\SupportTicketMessage;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(OpsServiceProvider::class);

        $this->academy = Academy::factory()->configured()->create();
        TenantContext::set($this->academy);

        $this->student = Student::factory()->create();
    }

    #[Test]
    public function opening_a_ticket_creates_the_first_message_with_it(): void
    {
        $ticket = app(OpenTicket::class)->handle(new OpenTicketData(
            subject: 'My score looks wrong',
            message: 'I got 40 for a perfect read aloud.',
            studentId: (int) $this->student->getKey(),
            priority: TicketPriority::High,
        ));

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame((int) $this->academy->getKey(), (int) $ticket->academy_id);

        $messages = SupportTicketMessage::query()->where('ticket_id', $ticket->getKey())->get();

        $this->assertCount(1, $messages);
        $this->assertSame(TicketSender::Student, $messages->first()?->sender_type);
        $this->assertFalse((bool) $messages->first()?->is_internal);
    }

    #[Test]
    public function a_staff_reply_moves_the_ticket_to_pending_and_a_student_reply_reopens_it(): void
    {
        $ticket = $this->openTicket();
        $staff = User::factory()->create();

        app(ReplyToTicket::class)->handle($ticket, 'We are looking into it.', TicketSender::Staff, (int) $staff->getKey());
        $this->assertSame(TicketStatus::Pending, $ticket->refresh()->status);

        app(ReplyToTicket::class)->handle($ticket, 'Thanks, still wrong.', TicketSender::Student, (int) $this->student->getKey());
        $this->assertSame(TicketStatus::Open, $ticket->refresh()->status);
    }

    #[Test]
    public function an_internal_note_neither_changes_the_status_nor_notifies_the_student(): void
    {
        Event::fake([TicketReplied::class]);

        $ticket = $this->openTicket();
        $staff = User::factory()->create();
        $before = $ticket->last_reply_at;

        app(ReplyToTicket::class)->handle(
            $ticket,
            'Checked the audio, it is 2 seconds of silence.',
            TicketSender::Staff,
            (int) $staff->getKey(),
            isInternal: true,
        );

        $this->assertSame(TicketStatus::Open, $ticket->refresh()->status);
        $this->assertEquals($before?->timestamp, $ticket->last_reply_at?->timestamp);

        Event::assertDispatched(
            TicketReplied::class,
            static fn (TicketReplied $event): bool => $event->notifiesStudent === false
        );
    }

    #[Test]
    public function a_staff_reply_announces_that_the_student_must_be_notified(): void
    {
        Event::fake([TicketReplied::class]);

        $ticket = $this->openTicket();
        $staff = User::factory()->create();

        app(ReplyToTicket::class)->handle($ticket, 'Fixed, sorry about that.', TicketSender::Staff, (int) $staff->getKey());

        Event::assertDispatched(
            TicketReplied::class,
            static fn (TicketReplied $event): bool => $event->notifiesStudent === true && $event->fromStaff === true
        );
    }

    #[Test]
    public function assigning_and_closing_a_ticket_stamps_who_did_it(): void
    {
        $ticket = $this->openTicket();
        $staff = User::factory()->create();

        app(AssignTicket::class)->handle($ticket, $staff);
        $this->assertSame((int) $staff->getKey(), (int) $ticket->refresh()->assigned_to);

        app(CloseTicket::class)->handle($ticket, (int) $staff->getKey(), 'Rescored the answer.');

        $ticket->refresh();
        $this->assertSame(TicketStatus::Closed, $ticket->status);
        $this->assertSame((int) $staff->getKey(), (int) $ticket->closed_by);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame(2, SupportTicketMessage::query()->where('ticket_id', $ticket->getKey())->count());
    }

    #[Test]
    public function a_closed_ticket_refuses_new_replies(): void
    {
        $ticket = $this->openTicket();
        app(CloseTicket::class)->handle($ticket);

        $this->expectException(TicketClosedException::class);

        app(ReplyToTicket::class)->handle($ticket, 'One more thing', TicketSender::Student, (int) $this->student->getKey());
    }

    #[Test]
    public function ticket_activity_is_written_to_the_academy_audit_trail(): void
    {
        $ticket = $this->openTicket();

        $this->assertTrue(
            ActivityLog::query()->forAction(AuditAction::TicketOpened->value)->forSubject($ticket)->exists()
        );
    }

    #[Test]
    public function tickets_never_cross_the_academy_boundary(): void
    {
        $this->openTicket();

        $other = Academy::factory()->configured()->create();

        TenantContext::runFor($other, function (): void {
            $this->assertSame(0, SupportTicket::query()->count());
            $this->assertSame(0, SupportTicketMessage::query()->count());
        });

        $this->assertSame(1, SupportTicket::query()->count());
    }

    private function openTicket(): SupportTicket
    {
        return app(OpenTicket::class)->handle(new OpenTicketData(
            subject: 'My score looks wrong',
            message: 'I got 40 for a perfect read aloud.',
            studentId: (int) $this->student->getKey(),
        ));
    }
}
