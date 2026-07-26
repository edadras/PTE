<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\SubscriptionManager;

/**
 * Cancellation keeps the customer running until the end of the period they
 * already paid for (docs/09 §3).
 */
final class CancelSubscription
{
    public function __construct(private readonly SubscriptionManager $subscriptions = new SubscriptionManager) {}

    public function handle(Subscription $subscription, bool $immediately = false, ?string $reason = null): Subscription
    {
        return $this->subscriptions->cancel($subscription, $immediately, $reason);
    }
}
