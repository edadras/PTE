<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Models\StudentSubscription;
use App\Domain\Identity\Models\Student;
use App\Domain\Tenancy\Models\Academy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentSubscription>
 */
final class StudentSubscriptionFactory extends Factory
{
    protected $model = StudentSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => Academy::factory(),
            'student_id' => Student::factory(),
            'plan_name' => 'پایه — ۱ ماه',
            'plan_key' => 'basic',
            'status' => StudentSubscription::STATUS_PENDING,
            // Minor units of IRR — the ۹۹۰٬۰۰۰ ریال tier from docs/09 §5.
            'price' => 990_000,
            'discount_amount' => 0,
            'credit_amount' => 0,
            'currency' => 'IRR',
            'duration_days' => 30,
            'auto_renew' => false,
            'entitlements' => ['practice_limit' => 50, 'ai_feedback' => true],
        ];
    }

    public function active(int $days = 30): static
    {
        $startsAt = CarbonImmutable::now();

        return $this->state(fn (): array => [
            'status' => StudentSubscription::STATUS_ACTIVE,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->addDays($days),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => StudentSubscription::STATUS_EXPIRED,
            'starts_at' => CarbonImmutable::now()->subDays(60),
            'expires_at' => CarbonImmutable::now()->subDays(30),
        ]);
    }
}
