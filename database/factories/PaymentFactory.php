<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Tenancy\Models\Academy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academy_id' => Academy::factory(),
            'amount' => 2_500_000,
            'platform_fee_amount' => 0,
            'refunded_amount' => 0,
            'currency' => 'IRR',
            'gateway' => PaymentGatewayKey::ZarinPal,
            'authority' => 'A'.Str::upper(Str::random(35)),
            'status' => PaymentStatus::Pending,
            'description' => 'Subscription',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'gateway_ref' => (string) fake()->numberBetween(1_000_000, 9_999_999),
            'paid_at' => now(),
        ]);
    }

    public function failed(string $code = 'gateway_declined'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'failure_code' => $code,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Refunded,
            'refunded_amount' => $attributes['amount'] ?? 0,
            'refunded_at' => now(),
        ]);
    }

    public function gateway(PaymentGatewayKey $gateway): static
    {
        return $this->state(fn (): array => ['gateway' => $gateway]);
    }
}
