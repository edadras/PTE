<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Data\PaymentResult;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\PaymentRecorded;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\StudentSubscription;
use App\Domain\Commerce\Models\Subscription;
use App\Domain\Commerce\Services\PaymentGatewayManager;
use App\Domain\Commerce\Services\SubscriptionManager;
use Illuminate\Support\Facades\DB;

/**
 * Turn a verified gateway result into a settled payment.
 *
 * The only entry point that may mark money as received. It re-verifies against
 * the gateway itself unless it is handed a result that already came from one —
 * a callback hitting our URL is never proof of payment (docs/09 §7).
 */
final class RecordPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways = new PaymentGatewayManager,
        private readonly SubscriptionManager $subscriptions = new SubscriptionManager,
    ) {}

    /**
     * Verify a reference server-side and settle the matching payment.
     */
    public function fromCallback(string $gatewayKey, string $reference): ?Payment
    {
        $payment = $this->locate($gatewayKey, $reference);

        if (! $payment instanceof Payment) {
            return null;
        }

        // Already settled: a duplicate callback must be a no-op, not a second
        // activation of the same subscription.
        if ($payment->status->isSettled()) {
            return $payment;
        }

        $result = $this->gateways->driver($gatewayKey)->verify($reference);

        return $this->handle($result, $payment);
    }

    public function handle(PaymentResult $result, ?Payment $payment = null): ?Payment
    {
        $payment ??= $this->locate($result->gateway->value, $result->reference);

        if (! $payment instanceof Payment) {
            return null;
        }

        if (! $result->successful) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_code' => $result->errorCode,
                'meta' => array_merge($payment->meta ?? [], ['verify' => $result->raw]),
            ])->save();

            return $payment;
        }

        // A gateway that confirms a different amount than we asked for is a
        // tampering signal, not a rounding difference.
        if ($result->amount !== null && $result->amount !== $payment->amount) {
            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failure_code' => 'amount_mismatch',
                'meta' => array_merge($payment->meta ?? [], ['verify' => $result->raw]),
            ])->save();

            return $payment;
        }

        DB::transaction(function () use ($payment, $result): void {
            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'gateway_ref' => $result->gatewayRef,
                'paid_at' => now(),
                'failure_code' => null,
                'meta' => array_merge($payment->meta ?? [], array_filter([
                    'card_mask' => $result->cardMask,
                    'verify' => $result->raw,
                ])),
            ])->save();
        });

        $this->settlePayable($payment);

        PaymentRecorded::dispatch($payment);

        return $payment;
    }

    /**
     * Advance whatever the payment was for. Student purchases are finished by
     * PurchaseStudentPlan, which owns the discount and referral side effects.
     */
    private function settlePayable(Payment $payment): void
    {
        if ($payment->payable_type === Subscription::class && $payment->payable_id !== null) {
            $subscription = Subscription::query()->forAcademy($payment->academy_id)->find($payment->payable_id);

            if ($subscription instanceof Subscription) {
                $subscription->status->isLive() && $subscription->current_period_end !== null
                    ? $this->subscriptions->renew($subscription, $payment)
                    : $this->subscriptions->activate($subscription, $payment);
            }

            return;
        }

        if ($payment->payable_type === StudentSubscription::class && $payment->payable_id !== null) {
            app(PurchaseStudentPlan::class)->activate($payment);
        }
    }

    private function locate(string $gatewayKey, string $reference): ?Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $gatewayKey)
            ->where(fn ($q) => $q->where('authority', $reference)->orWhere('gateway_ref', $reference))
            ->orderByDesc('id')
            ->first();

        return $payment;
    }
}
