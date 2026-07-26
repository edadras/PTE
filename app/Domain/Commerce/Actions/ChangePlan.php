<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\SubscriptionManager;

/**
 * Upgrade or downgrade.
 *
 * A downgrade never deletes anything that no longer fits the new plan — over-
 * quota resources stay readable and only new ones are blocked (docs/09 §2).
 */
final class ChangePlan
{
    public function __construct(private readonly SubscriptionManager $subscriptions = new SubscriptionManager) {}

    public function handle(Subscription $subscription, Plan $plan, ?BillingCycle $cycle = null): Subscription
    {
        return $this->subscriptions->changePlan($subscription, $plan, $cycle);
    }
}
