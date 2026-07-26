<?php

declare(strict_types=1);

namespace App\Domain\Integration\Enums;

/**
 * The complete, closed list of events an academy may subscribe to.
 *
 * Closed on purpose: an academy's CRM builds switch statements on these
 * strings, so adding one is a versioned decision and removing one is breaking.
 *
 * @see docs/08-api-and-integrations.md §5
 */
enum WebhookEvent: string
{
    case StudentCreated = 'student.created';
    case StudentUpdated = 'student.updated';
    case PracticeCompleted = 'practice.completed';
    case ExamSubmitted = 'exam.submitted';
    case ExamScored = 'exam.scored';
    case ScorePublished = 'score.published';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case SubscriptionExpiring = 'subscription.expiring';
    case SubscriptionExpired = 'subscription.expired';
    case SupportTicketCreated = 'support.ticket.created';

    public function label(): string
    {
        return __('api.webhook_events.'.$this->value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function tryFromMixed(string|self $event): ?self
    {
        return $event instanceof self ? $event : self::tryFrom($event);
    }
}
