<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\SubscriptionManager;

/**
 * 14 days of Professional, no card required (docs/09 §1).
 */
final class StartTrial
{
    public function __construct(private readonly SubscriptionManager $subscriptions = new SubscriptionManager) {}

    public function handle(int $academyId, ?Plan $plan = null, int $days = SubscriptionManager::TRIAL_DAYS): Subscription
    {
        return $this->subscriptions->startTrial($academyId, $plan, $days);
    }
}
