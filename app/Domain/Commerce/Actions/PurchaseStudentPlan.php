<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Contracts\ReferralCreditProvider;
use App\Domain\Commerce\Data\DiscountResult;
use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Data\PaymentSession;
use App\Domain\Commerce\Data\StudentPlanPurchase;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\StudentSubscriptionActivated;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\StudentSubscription;
use App\Domain\Commerce\Services\NullReferralCredits;
use App\Domain\Commerce\Services\PaymentGatewayManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The B2C purchase flow from docs/09 §5:
 *
 *   pick plan → apply discount + referral credit → payment link → gateway
 *   callback → activate → receipt.
 *
 * Nothing is activated until a payment is verified server-side; a zero-priced
 * order (fully covered by discount or credit) is the one case that skips the
 * gateway, and it skips it deliberately rather than by accident.
 */
final class PurchaseStudentPlan
{
    /** Platform commission on B2C sales routed through our gateway (docs/09 §5). */
    private const DEFAULT_PLATFORM_FEE_BP = 300;

    public function __construct(
        private readonly PaymentGatewayManager $gateways = new PaymentGatewayManager,
        private readonly ApplyDiscountCode $discounts = new ApplyDiscountCode,
        private readonly ReferralCreditProvider $credits = new NullReferralCredits,
    ) {}

    /**
     * @return array{subscription: StudentSubscription, payment: Payment|null, session: PaymentSession|null, discount: DiscountResult|null}
     */
    public function handle(StudentPlanPurchase $purchase): array
    {
        $discount = $purchase->discountCode === null
            ? null
            : $this->discounts->handle(
                $purchase->academyId,
                $purchase->discountCode,
                $purchase->price,
                $purchase->currency,
                $purchase->studentId,
            );

        $discountAmount = $discount?->discountAmount ?? 0;
        $afterDiscount = $purchase->price - $discountAmount;

        $creditAmount = $purchase->useReferralCredit
            ? min($afterDiscount, $this->credits->balanceFor($purchase->academyId, $purchase->studentId, $purchase->currency))
            : 0;

        $payable = max(0, $afterDiscount - $creditAmount);

        $subscription = new StudentSubscription;
        $subscription->forceFill([
            'academy_id' => $purchase->academyId,
            'student_id' => $purchase->studentId,
            'course_id' => $purchase->courseId,
            'plan_name' => $purchase->planName,
            'plan_key' => $purchase->planKey,
            'status' => StudentSubscription::STATUS_PENDING,
            'price' => $purchase->price,
            'discount_amount' => $discountAmount,
            'credit_amount' => $creditAmount,
            'currency' => $purchase->currency,
            'duration_days' => $purchase->durationDays,
            'auto_renew' => $purchase->autoRenew,
            'entitlements' => $purchase->entitlements,
            'meta' => $purchase->metadata + array_filter(['discount_code' => $purchase->discountCode]),
        ])->save();

        if ($payable === 0) {
            $this->finalise($subscription, null, $discount);

            return ['subscription' => $subscription, 'payment' => null, 'session' => null, 'discount' => $discount];
        }

        $driver = $purchase->gateway === null
            ? $this->gateways->default()
            : $this->gateways->configuredDriver($purchase->gateway);

        $session = $driver->createPayment(new PaymentRequest(
            academyId: $purchase->academyId,
            amount: $payable,
            currency: $purchase->currency,
            description: $purchase->planName,
            callbackUrl: $purchase->callbackUrl,
            gateway: $driver->key(),
            payableType: StudentSubscription::class,
            payableId: $subscription->getKey(),
            metadata: $purchase->metadata,
        ));

        $payment = new Payment;
        $payment->forceFill([
            'academy_id' => $purchase->academyId,
            'payable_type' => StudentSubscription::class,
            'payable_id' => $subscription->getKey(),
            'amount' => $payable,
            'platform_fee_amount' => $this->platformFee($payable),
            'currency' => $purchase->currency,
            'gateway' => $driver->key(),
            'authority' => $session->reference,
            'status' => PaymentStatus::Pending,
            'description' => $purchase->planName,
            'meta' => $session->raw,
        ])->save();

        $subscription->forceFill(['payment_id' => $payment->getKey()])->save();

        return ['subscription' => $subscription, 'payment' => $payment, 'session' => $session, 'discount' => $discount];
    }

    /**
     * Called by RecordPayment once the gateway has confirmed the money.
     */
    public function activate(Payment $payment): ?StudentSubscription
    {
        /** @var StudentSubscription|null $subscription */
        $subscription = StudentSubscription::query()
            ->forAcademy($payment->academy_id)
            ->find($payment->payable_id);

        if (! $subscription instanceof StudentSubscription) {
            return null;
        }

        if ($subscription->status === StudentSubscription::STATUS_ACTIVE) {
            return $subscription;
        }

        $discountCode = $subscription->meta['discount_code'] ?? null;

        $discount = is_string($discountCode)
            ? $this->discounts->handle(
                $subscription->academy_id,
                $discountCode,
                $subscription->price,
                $subscription->currency,
                $subscription->student_id,
            )
            : null;

        $this->finalise($subscription, $payment, $discount);

        return $subscription;
    }

    /**
     * Activate, burn the discount and the credit, and reward the referrer.
     *
     * All in one transaction: a student must never end up with an active
     * subscription and an unconsumed credit, or vice versa.
     */
    private function finalise(StudentSubscription $subscription, ?Payment $payment, ?DiscountResult $discount): void
    {
        DB::transaction(function () use ($subscription, $payment, $discount): void {
            $startsAt = CarbonImmutable::now();

            $subscription->forceFill([
                'status' => StudentSubscription::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'expires_at' => $subscription->duration_days === null
                    ? null
                    : $startsAt->addDays($subscription->duration_days),
                'payment_id' => $payment?->getKey() ?? $subscription->payment_id,
            ])->save();

            if ($discount instanceof DiscountResult && $discount->valid) {
                $this->discounts->redeem($discount, $subscription->student_id, $subscription, $payment);
            }

            if ($subscription->credit_amount > 0) {
                $this->credits->consume(
                    $subscription->academy_id,
                    $subscription->student_id,
                    $subscription->credit_amount,
                    $subscription->currency,
                );
            }

            $this->credits->rewardReferrer(
                $subscription->academy_id,
                $subscription->student_id,
                $subscription->payableAmount(),
                $subscription->currency,
            );
        });

        StudentSubscriptionActivated::dispatch($subscription, $payment);
    }

    private function platformFee(int $amount): int
    {
        $bp = (int) config('pte.commerce.platform_fee_bp', self::DEFAULT_PLATFORM_FEE_BP);

        return intdiv($amount * $bp + 5_000, 10_000);
    }
}
