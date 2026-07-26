<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * @see docs/09-billing-and-plans.md §1
 */
enum PlanKey: string
{
    case Trial = 'trial';
    case Starter = 'starter';
    case Professional = 'professional';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return __('billing.plan.'.$this->value);
    }

    /** Trial is not sold; it is granted. */
    public function isPurchasable(): bool
    {
        return $this !== self::Trial;
    }

    /** Enterprise pricing is agreed per customer, so there is no list price. */
    public function isNegotiated(): bool
    {
        return $this === self::Enterprise;
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Trial => 0,
            self::Starter => 10,
            self::Professional => 20,
            self::Enterprise => 30,
        };
    }
}
