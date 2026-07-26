<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Services\SubscriptionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily: suspend academies that have been past_due for the full grace period.
 *
 * Suspension is the first state where the bot stops answering, so it is
 * deliberately the last step and never happens before the 7 days are up
 * (docs/09 §3). Data is not touched here — deletion is a separate, much later
 * decision.
 */
final class SuspendPastDueAcademies implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(SubscriptionManager $subscriptions): void
    {
        foreach ($subscriptions->suspendable() as $subscription) {
            try {
                $subscriptions->suspend($subscription);
            } catch (Throwable $e) {
                Log::error('Failed to suspend a past-due academy.', [
                    'subscription_id' => $subscription->getKey(),
                    'academy_id' => $subscription->academy_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['commerce', 'billing-dunning'];
    }
}
