<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Commerce\Events\SubscriptionCanceled;
use App\Domain\Commerce\Events\SubscriptionStatusChanged;

/**
 * Mandatory audit (docs/02 §7): plan and billing state changes.
 */
final class RecordSubscriptionChange
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function handle(SubscriptionStatusChanged $event): void
    {
        $subscription = $event->subscription;

        $this->recorder->record(
            AuditAction::SubscriptionStatusChanged,
            $subscription,
            ['status' => $event->from->value],
            [
                'status' => $event->to->value,
                'plan_id' => $subscription->plan_id,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            (int) $subscription->academy_id,
        );
    }

    public function handleCanceled(SubscriptionCanceled $event): void
    {
        $subscription = $event->subscription;

        $this->recorder->record(
            AuditAction::PlanChanged,
            $subscription,
            [],
            [
                'canceled' => true,
                'immediately' => $event->immediately,
                'reason' => $event->reason,
                'plan_id' => $subscription->plan_id,
            ],
            (int) $subscription->academy_id,
        );
    }
}
