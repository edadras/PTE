<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Jobs;

use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\SubscriptionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily: the 7 / 3 / 1 day reminder schedule (docs/09 §3).
 *
 * Emits RenewalReminderDue and marks the reminder as sent on the subscription,
 * so re-running the scheduler after an outage does not double-mail anyone.
 */
final class SendRenewalReminders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?int $onlyAcademyId = null) {}

    public function handle(SubscriptionManager $subscriptions): void
    {
        foreach (Subscription::REMINDER_DAYS as $days) {
            foreach ($subscriptions->dueForReminder($days) as $subscription) {
                if ($this->onlyAcademyId !== null && $subscription->academy_id !== $this->onlyAcademyId) {
                    continue;
                }

                $subscriptions->sendReminder($subscription, $days);
            }
        }
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['commerce', 'billing-reminders'];
    }
}
