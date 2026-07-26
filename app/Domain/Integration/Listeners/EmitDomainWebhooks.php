<?php

declare(strict_types=1);

namespace App\Domain\Integration\Listeners;

use App\Domain\Assessment\Events\ExamSessionSubmitted;
use App\Domain\Assessment\Events\PracticeSessionCompleted;
use App\Domain\Assessment\Events\SessionScored;
use App\Domain\Assessment\Models\ExamSession;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Events\PaymentRecorded;
use App\Domain\Commerce\Events\RenewalReminderDue;
use App\Domain\Commerce\Events\SubscriptionStatusChanged;
use App\Domain\Integration\Enums\WebhookEvent;
use App\Domain\Integration\Services\WebhookEmitter;
use App\Domain\Support\Events\TicketOpened;
use BackedEnum;
use Illuminate\Events\Dispatcher;

/**
 * Translates domain events into the public event vocabulary of docs/08 §5.
 *
 * The mapping lives here rather than in each context so that Assessment and
 * Commerce stay unaware that an integration layer exists at all.
 */
final class EmitDomainWebhooks
{
    public function __construct(private readonly WebhookEmitter $emitter) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PracticeSessionCompleted::class, [self::class, 'onPracticeCompleted']);
        $events->listen(ExamSessionSubmitted::class, [self::class, 'onExamSubmitted']);
        $events->listen(SessionScored::class, [self::class, 'onSessionScored']);
        $events->listen(PaymentRecorded::class, [self::class, 'onPaymentRecorded']);
        $events->listen(SubscriptionStatusChanged::class, [self::class, 'onSubscriptionStatusChanged']);
        $events->listen(RenewalReminderDue::class, [self::class, 'onRenewalReminderDue']);
        $events->listen(TicketOpened::class, [self::class, 'onTicketOpened']);
    }

    public function onPracticeCompleted(PracticeSessionCompleted $event): void
    {
        $session = $event->session;

        $this->emitter->emit(WebhookEvent::PracticeCompleted, [
            'session_id' => (int) $session->getKey(),
            'student_id' => (int) $session->student_id,
            'module' => $session->module_key instanceof BackedEnum
                ? $session->module_key->value
                : $session->module_key,
            'status' => $session->status instanceof BackedEnum ? $session->status->value : $session->status,
            'total_questions' => (int) $session->total_questions,
            'answered' => (int) $session->answered,
            'completed_at' => $session->completed_at?->toIso8601String(),
        ], (int) $session->academy_id);
    }

    public function onExamSubmitted(ExamSessionSubmitted $event): void
    {
        $session = $event->session;

        $this->emitter->emit(WebhookEvent::ExamSubmitted, [
            'session_id' => (int) $session->getKey(),
            'exam_id' => (int) $session->exam_id,
            'student_id' => (int) $session->student_id,
            'attempt_number' => (int) $session->attempt_number,
            'automatic' => $event->automatic,
            'submitted_at' => $session->submitted_at?->toIso8601String(),
        ], (int) $session->academy_id);
    }

    public function onSessionScored(SessionScored $event): void
    {
        $score = $event->score;
        $academyId = (int) $score->academy_id;

        $payload = [
            'score_id' => (int) $score->getKey(),
            'session_type' => $score->session_type instanceof BackedEnum
                ? $score->session_type->value
                : $score->session_type,
            'session_id' => (int) $score->session_id,
            'student_id' => (int) $score->student_id,
            'raw_score' => (float) $score->raw_score,
            'percentage' => (float) $score->percentage,
            'breakdown' => $score->breakdown,
        ];

        if ($event->session instanceof ExamSession) {
            $this->emitter->emit(WebhookEvent::ExamScored, $payload, $academyId);
        }

        if ($score->published_at !== null) {
            $this->emitter->emit(WebhookEvent::ScorePublished, $payload, $academyId);
        }
    }

    public function onPaymentRecorded(PaymentRecorded $event): void
    {
        $payment = $event->payment;

        $webhookEvent = $payment->status === PaymentStatus::Failed
            ? WebhookEvent::PaymentFailed
            : WebhookEvent::PaymentSucceeded;

        if ($payment->status !== PaymentStatus::Failed && ! $payment->isPaid()) {
            return;
        }

        $this->emitter->emit($webhookEvent, [
            'payment_id' => (int) $payment->getKey(),
            'amount' => (int) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status instanceof BackedEnum ? $payment->status->value : $payment->status,
            'gateway' => $payment->gateway instanceof BackedEnum ? $payment->gateway->value : $payment->gateway,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ], (int) $payment->academy_id);
    }

    public function onSubscriptionStatusChanged(SubscriptionStatusChanged $event): void
    {
        if ($event->to !== SubscriptionStatus::Expired) {
            return;
        }

        $this->emitter->emit(WebhookEvent::SubscriptionExpired, [
            'subscription_id' => (int) $event->subscription->getKey(),
            'from' => $event->from->value,
            'to' => $event->to->value,
        ], (int) $event->subscription->academy_id);
    }

    public function onTicketOpened(TicketOpened $event): void
    {
        $ticket = $event->ticket;

        $this->emitter->emit(WebhookEvent::SupportTicketCreated, [
            'ticket_id' => (int) $ticket->getKey(),
            'student_id' => $ticket->student_id === null ? null : (int) $ticket->student_id,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority->value,
            'source' => $ticket->source->value,
        ], (int) $ticket->academy_id);
    }

    public function onRenewalReminderDue(RenewalReminderDue $event): void
    {
        $this->emitter->emit(WebhookEvent::SubscriptionExpiring, [
            'subscription_id' => (int) $event->subscription->getKey(),
            'days_before' => $event->daysBefore,
            'ends_at' => $event->subscription->ends_at?->toIso8601String(),
        ], (int) $event->subscription->academy_id);
    }
}
