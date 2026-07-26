<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\SubscriptionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily: advance every subscription whose period has run out.
 *
 * Platform-level, not tenant-level — it has to see all academies at once, which
 * is why it does not extend TenantAwareJob.
 *
 * @see docs/09-billing-and-plans.md §3
 */
final class CheckSubscriptionExpiry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?int $onlyAcademyId = null) {}

    public function handle(SubscriptionManager $subscriptions): void
    {
        foreach ($subscriptions->expiredPeriods() as $subscription) {
            if ($this->onlyAcademyId !== null && $subscription->academy_id !== $this->onlyAcademyId) {
                continue;
            }

            $this->advance($subscriptions, $subscription);
        }
    }

    private function advance(SubscriptionManager $subscriptions, Subscription $subscription): void
    {
        try {
            match ($subscription->status) {
                // A trial that was never paid for simply ends — nothing is
                // deleted, the academy just drops to no plan.
                SubscriptionStatus::Trialing => $subscriptions->expire($subscription),
                // A cancelled subscription reaching its paid-through date ends.
                SubscriptionStatus::Canceled => $subscriptions->expire($subscription),
                // An active one that did not renew enters dunning; the bot
                // keeps working throughout (docs/09 §3).
                SubscriptionStatus::Active => $subscriptions->markPastDue($subscription),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Failed to advance a subscription past its period end.', [
                'subscription_id' => $subscription->getKey(),
                'academy_id' => $subscription->academy_id,
                'status' => $subscription->status->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['commerce', 'billing-cycle'];
    }
}
