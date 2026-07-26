<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Data\PaymentSession;
use App\Domain\Commerce\Enums\BillingCycle;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\SubscriptionStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Plan;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\PaymentGatewayManager;
use App\Domain\Commerce\Services\SubscriptionManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B2B checkout: put an academy on a paid plan and open a payment session.
 *
 * The subscription is not activated here — only a verified payment does that
 * (see RecordPayment), because a redirect proves nothing (docs/09 §7).
 *
 * @phpstan-type Checkout array{subscription: Subscription, payment: Payment, session: PaymentSession}
 */
final class SubscribeAcademy
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions = new SubscriptionManager,
        private readonly PaymentGatewayManager $gateways = new PaymentGatewayManager,
    ) {}

    /**
     * @return array{subscription: Subscription, payment: Payment, session: PaymentSession}
     */
    public function handle(
        int $academyId,
        Plan $plan,
        BillingCycle $cycle = BillingCycle::Monthly,
        ?PaymentGatewayKey $gateway = null,
        ?string $callbackUrl = null,
    ): array {
        $price = $plan->priceFor($cycle);

        if ($price === null) {
            // Enterprise is quoted by a human; there is no self-serve checkout.
            throw new InvalidArgumentException("Plan [{$plan->key}] has no list price for the {$cycle->value} cycle.");
        }

        $subscription = $this->subscriptionFor($academyId);

        $subscription->forceFill([
            'academy_id' => $academyId,
            'plan_id' => $plan->getKey(),
            'billing_cycle' => $cycle,
            'price' => $price,
            'currency' => $plan->currency,
            'auto_renew' => true,
        ])->save();

        $driver = $gateway === null ? $this->gateways->default() : $this->gateways->configuredDriver($gateway);

        $request = new PaymentRequest(
            academyId: $academyId,
            amount: $price,
            currency: $plan->currency,
            description: __('billing.invoice.line_subscription', [
                'plan' => $plan->label(),
                'cycle' => $cycle->label(),
            ]),
            callbackUrl: $callbackUrl,
            gateway: $driver->key(),
            payableType: Subscription::class,
            payableId: $subscription->getKey(),
        );

        $session = $driver->createPayment($request);

        $payment = DB::transaction(function () use ($academyId, $price, $plan, $driver, $session, $subscription, $request): Payment {
            $payment = new Payment;
            $payment->forceFill([
                'academy_id' => $academyId,
                'payable_type' => Subscription::class,
                'payable_id' => $subscription->getKey(),
                'amount' => $price,
                'currency' => $plan->currency,
                'gateway' => $driver->key(),
                'authority' => $session->reference,
                'status' => PaymentStatus::Pending,
                'description' => $request->description,
                'meta' => $session->raw,
            ])->save();

            return $payment;
        });

        return ['subscription' => $subscription, 'payment' => $payment, 'session' => $session];
    }

    private function subscriptionFor(int $academyId): Subscription
    {
        $existing = Subscription::query()
            ->forAcademy($academyId)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Suspended->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($existing instanceof Subscription) {
            return $existing;
        }

        $subscription = new Subscription;
        $subscription->forceFill([
            'academy_id' => $academyId,
            'status' => SubscriptionStatus::Trialing,
            'reminders_sent' => [],
        ]);

        return $subscription;
    }
}
