<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Canceled = 'canceled';

    public function label(): string
    {
        return __('billing.payment_status.'.$this->value);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function isRefundable(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], true);
    }
}
