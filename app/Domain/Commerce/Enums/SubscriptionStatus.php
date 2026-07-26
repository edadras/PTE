<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * The billing state machine from docs/09 §3:
 *
 *   trialing → active → past_due → suspended
 *   trialing → expired          past_due → active
 *   active   → canceled → expired
 *
 * The two behavioural questions every caller actually asks are answered here:
 * may the panel write, and may the bot keep serving students.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function label(): string
    {
        return __('billing.subscription_status.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::Expired, self::Canceled],
            self::Active => [self::PastDue, self::Canceled, self::Expired],
            self::PastDue => [self::Active, self::Suspended, self::Canceled],
            self::Suspended => [self::Active, self::Expired],
            self::Canceled => [self::Expired, self::Active],
            self::Expired => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** The academy is entitled to the plan right now. */
    public function isLive(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /**
     * Students must never pay for their academy's unpaid invoice, so the bot
     * keeps answering all the way through past_due (docs/09 §3).
     */
    public function allowsBotTraffic(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    /** In past_due the panel becomes read-only — that is the actual lever. */
    public function allowsPanelWrites(): bool
    {
        return in_array($this, [self::Trialing, self::Active], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Expired;
    }
}
