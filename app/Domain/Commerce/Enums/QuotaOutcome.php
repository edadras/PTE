<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * @see docs/09-billing-and-plans.md §2
 */
enum QuotaOutcome: string
{
    case Unlimited = 'unlimited';
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Exceeded = 'exceeded';

    /** Ratio at which each outcome starts. */
    public const WARNING_RATIO = 0.80;

    public const CRITICAL_RATIO = 0.95;

    public function label(): string
    {
        return __('billing.quota_outcome.'.$this->value);
    }

    public function allowed(): bool
    {
        return $this !== self::Exceeded;
    }

    /** Warning and critical are informational — callers must not block on them. */
    public function isAdvisory(): bool
    {
        return in_array($this, [self::Warning, self::Critical], true);
    }

    public function shouldNotifyAdmins(): bool
    {
        return in_array($this, [self::Critical, self::Exceeded], true);
    }
}
