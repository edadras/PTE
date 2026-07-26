<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Enums\PlanKey;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Events\AcademySuspended;
use App\Domain\Commerce\Events\RenewalReminderDue;
use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Domain\Commerce\Events\SubscriptionCanceled;
use App\Domain\Commerce\Events\SubscriptionStatusChanged;
use App\Domain\Commerce\Events\TrialStarted;
use App\Domain\Commerce\Exceptions\InvalidSubscriptionTransitionException;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The billing state machine from docs/09 §3.
 *
 *   trialing → active → past_due → suspended
 *   trialing → expired      past_due → active
 *   active   → canceled → expired
 *
 * Two behaviours the rest of the platform depends on and that live on the
 * model, not here: Subscription::allowsBotTraffic() stays true through
 * past_due, and allowsPanelWrites() goes false there.
 */
final class SubscriptionManager
{
    public const TRIAL_DAYS = 14;

    public function __construct(private readonly InvoiceGenerator $invoices = new InvoiceGenerator) {}

    /**
     * 14 days of Professional, no card (docs/09 §1). The trial pseudo-plan
     * carries the reduced AI ceiling, so nothing here special-cases quotas.
     */
    public function startTrial(int $academyId, ?Plan $plan = null, int $days = self::TRIAL_DAYS): Subscription
    {
        $plan ??= Plan::findByKey(PlanKey::Trial) ?? Plan::findByKey(PlanKey::Professional);

        if (! $plan instanceof Plan) {
            throw new InvalidSubscriptionTransitionException('No plan is available to start a trial with.');
        }

        $now = CarbonImmutable::now();
        $endsAt = $now->addDays($days);

        $subscription = new Subscription;
        $subscription->forceFill([
            'academy_id' => $academyId,
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Trialing,
            'billing_cycle' => BillingCycle::Monthly,
            'price' => 0,
            'currency' => $plan->currency,
            'started_at' => $now,
            'trial_ends_at' => $endsAt,
            'current_period_start' => $now,
            'current_period_end' => $endsAt,
            'auto_renew' => false,
            'reminders_sent' => [],
        ])->save();

        $this->syncAcademy($subscription);

        TrialStarted::dispatch($subscription);

        return $subscription;
    }

    /**
     * Move to active and open a fresh billing period.
     */
    public function activate(Subscription $subscription, ?Payment $payment = null, ?CarbonInterface $from = null): Subscription
    {
        $from = CarbonImmutable::instance($from ?? now());
        $cycle = $subscription->billing_cycle;

        $this->transition($subscription, SubscriptionStatus::Active, [
            'started_at' => $subscription->started_at ?? $from,
            'current_period_start' => $from,
            'current_period_end' => $cycle->advance($from),
            'past_due_at' => null,
            'suspended_at' => null,
            'canceled_at' => null,
            'ends_at' => null,
            'reminders_sent' => [],
        ]);

        $this->syncAcademy($subscription);

        SubscriptionActivated::dispatch($subscription, $payment);

        return $subscription;
    }

    /**
     * Roll the period forward on a successful renewal charge.
     */
    public function renew(Subscription $subscription, ?Payment $payment = null): Subscription
    {
        // Anchor on the old period end, not on "now": a renewal processed a day
        // late must not silently shorten the customer's next month.
        $anchor = CarbonImmutable::instance($subscription->current_period_end ?? now());

        if ($anchor->isPast()) {
            $anchor = CarbonImmutable::now();
        }

        return $this->activate($subscription, $payment, $anchor);
    }

    /**
     * Payment failed or the period lapsed. Panel goes read-only; the bot does
     * not — students are not the ones who missed the invoice.
     */
    public function markPastDue(Subscription $subscription, ?CarbonInterface $at = null): Subscription
    {
        if ($subscription->status === SubscriptionStatus::PastDue) {
            return $subscription;
        }

        return $this->transition($subscription, SubscriptionStatus::PastDue, [
            'past_due_at' => $at ?? now(),
        ]);
    }

    public function suspend(Subscription $subscription): Subscription
    {
        $this->transition($subscription, SubscriptionStatus::Suspended, [
            'suspended_at' => now(),
        ]);

        AcademySuspended::dispatch($subscription);

        return $subscription;
    }

    /**
     * Cancellation is not termination: by default the customer keeps what they
     * paid for until the period ends, and only then expires.
     */
    public function cancel(Subscription $subscription, bool $immediately = false, ?string $reason = null): Subscription
    {
        $endsAt = $immediately ? now() : ($subscription->current_period_end ?? now());

        $this->transition($subscription, SubscriptionStatus::Canceled, [
            'canceled_at' => now(),
            'ends_at' => $endsAt,
            'auto_renew' => false,
            'meta' => array_merge($subscription->meta ?? [], array_filter(['cancel_reason' => $reason])),
        ]);

        SubscriptionCanceled::dispatch($subscription, $immediately, $reason);

        if ($immediately) {
            $this->expire($subscription);
        }

        return $subscription;
    }

    public function expire(Subscription $subscription): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::Expired, [
            'ends_at' => $subscription->ends_at ?? now(),
            'auto_renew' => false,
        ]);
    }

    /** Bring a suspended or canceled academy back, typically after payment. */
    public function resume(Subscription $subscription, ?Payment $payment = null): Subscription
    {
        return $this->activate($subscription, $payment);
    }

    public function changePlan(Subscription $subscription, Plan $plan, ?BillingCycle $cycle = null): Subscription
    {
        $cycle ??= $subscription->billing_cycle;

        $subscription->forceFill([
            'plan_id' => $plan->getKey(),
            'billing_cycle' => $cycle,
            'price' => $plan->priceFor($cycle) ?? $subscription->price,
            'currency' => $plan->currency,
        ])->save();

        $this->syncAcademy($subscription);

        return $subscription->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(Subscription $subscription, SubscriptionStatus $to, array $attributes = []): Subscription
    {
        $from = $subscription->status;

        if ($from !== $to && ! $from->canTransitionTo($to)) {
            throw InvalidSubscriptionTransitionException::between($from, $to);
        }

        DB::transaction(function () use ($subscription, $to, $attributes): void {
            $subscription->forceFill(array_merge($attributes, ['status' => $to]))->save();
        });

        if ($from !== $to) {
            SubscriptionStatusChanged::dispatch($subscription, $from, $to);
        }

        return $subscription;
    }

    // ---------------------------------------------------------------- queries

    /**
     * Subscriptions whose period ends in exactly $days days and that have not
     * already had that reminder.
     *
     * @return Collection<int, Subscription>
     */
    public function dueForReminder(int $days, ?CarbonInterface $at = null): Collection
    {
        $at = CarbonImmutable::instance($at ?? now());
        $target = $at->addDays($days);

        /** @var Collection<int, Subscription> $due */
        $due = Subscription::query()
            ->withoutGlobalScope('academy')
            ->live()
            ->where('auto_renew', true)
            ->whereBetween('current_period_end', [$target->startOfDay(), $target->endOfDay()])
            ->get()
            ->reject(static fn (Subscription $s): bool => $s->hasSentReminder($days))
            ->values();

        return $due;
    }

    public function sendReminder(Subscription $subscription, int $days): void
    {
        RenewalReminderDue::dispatch($subscription, $days);

        $subscription->markReminderSent($days);
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function expiredPeriods(?CarbonInterface $at = null): Collection
    {
        /** @var Collection<int, Subscription> $expired */
        $expired = Subscription::query()
            ->withoutGlobalScope('academy')
            ->withStatus(SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::Canceled)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $at ?? now())
            ->get();

        return $expired;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function suspendable(?CarbonInterface $at = null): Collection
    {
        $at ??= now();

        /** @var Collection<int, Subscription> $suspendable */
        $suspendable = Subscription::query()
            ->withoutGlobalScope('academy')
            ->withStatus(SubscriptionStatus::PastDue)
            ->whereNotNull('past_due_at')
            ->where('past_due_at', '<=', $at->copy()->subDays(Subscription::PAST_DUE_GRACE_DAYS))
            ->get();

        return $suspendable;
    }

    /** @return array<int, int> */
    public function reminderSchedule(): array
    {
        return Subscription::REMINDER_DAYS;
    }

    public function invoices(): InvoiceGenerator
    {
        return $this->invoices;
    }

    /**
     * Keep `academies.plan_id` / `trial_ends_at` in step so tenant resolution
     * never has to join the subscription table on every request.
     */
    private function syncAcademy(Subscription $subscription): void
    {
        DB::table('academies')->where('id', $subscription->academy_id)->update([
            'plan_id' => $subscription->plan_id,
            'trial_ends_at' => $subscription->trial_ends_at,
            'updated_at' => now(),
        ]);
    }
}
