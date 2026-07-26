<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\StudentSubscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A student's purchase is live. Whoever grants course access, sends the receipt
 * or pays out a referral credit hangs off this (docs/09 §5).
 */
final class StudentSubscriptionActivated
{
    use Dispatchable;

    public function __construct(
        public readonly StudentSubscription $subscription,
        public readonly ?Payment $payment = null,
    ) {}
}
