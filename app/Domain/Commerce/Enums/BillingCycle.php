<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return __('billing.cycle.'.$this->value);
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Yearly => 12,
        };
    }

    public function advance(CarbonInterface $from): CarbonInterface
    {
        return $from->copy()->addMonthsNoOverflow($this->months());
    }

    /** Column on `plans` holding this cycle's list price. */
    public function priceColumn(): string
    {
        return match ($this) {
            self::Monthly => 'price_monthly',
            self::Yearly => 'price_yearly',
        };
    }
}
