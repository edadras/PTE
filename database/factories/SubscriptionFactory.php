<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Tenancy\Models\Academy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfDay();

        return [
            'academy_id' => Academy::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'price' => 2_500_000,
            'currency' => 'IRR',
            'started_at' => $start,
            'current_period_start' => $start,
            'current_period_end' => $start->addMonthNoOverflow(),
            'auto_renew' => true,
            'reminders_sent' => [],
        ];
    }

    public function trialing(int $days = 14): static
    {
        $endsAt = CarbonImmutable::now()->addDays($days);

        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Trialing,
            'price' => 0,
            'trial_ends_at' => $endsAt,
            'current_period_end' => $endsAt,
            'auto_renew' => false,
        ]);
    }

    public function pastDue(int $daysAgo = 1): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::PastDue,
            'past_due_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Suspended,
            'past_due_at' => CarbonImmutable::now()->subDays(8),
            'suspended_at' => CarbonImmutable::now(),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => CarbonImmutable::now(),
            'auto_renew' => false,
        ]);
    }

    public function renewingIn(int $days): static
    {
        return $this->state(fn (): array => [
            'current_period_end' => CarbonImmutable::now()->addDays($days),
        ]);
    }
}
