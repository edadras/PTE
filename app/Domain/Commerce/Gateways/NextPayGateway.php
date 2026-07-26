<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Data\PaymentResult;
use App\Domain\Commerce\Data\PaymentSession;
use App\Domain\Commerce\Data\RefundResult;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Models\Payment;

/**
 * NextPay gateway v2 REST (`nextpay.org/nx/gateway`).
 *
 * config('services.nextpay'): api_key, (optional) callback_url.
 *
 * NextPay prices in Toman by default; we store Rials, so every request states
 * `currency` explicitly rather than trusting a default that is off by 10x.
 */
final class NextPayGateway extends AbstractGateway
{
    private const BASE = 'https://nextpay.org/nx/gateway';

    /** Token endpoint success — yes, NextPay's "token issued" code is -1. */
    private const CODE_TOKEN_ISSUED = -1;

    /** Verify endpoint success: payment settled. */
    private const CODE_PAID = 0;

    /** Refund performed (verify endpoint with refund_request). */
    private const CODE_REFUNDED = -90;

    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::NextPay;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('api_key'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $orderId = $this->orderId($r);

        $response = $this->http()->post(self::BASE.'/token', array_filter([
            'api_key' => (string) $this->config('api_key'),
            'order_id' => $orderId,
            'amount' => $r->amount,
            'currency' => $r->currency,
            'callback_uri' => $this->callbackUrl($r),
            'customer_phone' => $r->payerMobile,
            'payer_name' => $r->payerName,
            'payer_desc' => $r->description,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $code = (int) ($response->json('code') ?? -999);
        $transId = (string) ($response->json('trans_id') ?? '');

        if ($code !== self::CODE_TOKEN_ISSUED || $transId === '') {
            $this->fail('code='.$code);
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $transId,
            redirectUrl: self::BASE.'/payment/'.$transId,
            raw: ['order_id' => $orderId] + (array) $response->json(),
        );
    }

    /**
     * $reference is the trans_id. The stored amount is re-sent — NextPay
     * refuses a mismatch — and the amount it echoes back is compared again,
     * so a tampered callback can never settle for less than was owed.
     */
    public function verify(string $reference, ?int $amount = null): PaymentResult
    {
        $amount ??= $this->amountForTransId($reference);

        if ($amount === null) {
            return PaymentResult::failed($this->key(), $reference, 'unknown_reference');
        }

        $response = $this->http()->post(self::BASE.'/verify', [
            'api_key' => (string) $this->config('api_key'),
            'trans_id' => $reference,
            'amount' => $amount,
            'currency' => 'IRR',
        ]);

        $code = (int) ($response->json('code') ?? -999);

        if ($code !== self::CODE_PAID) {
            return PaymentResult::failed($this->key(), $reference, (string) $code, null, (array) $response->json());
        }

        $confirmed = $response->json('amount');

        if ($confirmed !== null && (int) $confirmed !== $amount) {
            return PaymentResult::failed($this->key(), $reference, 'amount_mismatch', null, (array) $response->json());
        }

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: (string) ($response->json('Shaparak_Ref_Id') ?? ''),
            amount: $amount,
            currency: 'IRR',
            // card_holder is NextPay's field name for the *masked* PAN.
            cardMask: ($pan = $response->json('card_holder')) !== null ? (string) $pan : null,
            raw: (array) $response->json(),
        );
    }

    /**
     * NextPay refunds through the verify endpoint with `refund_request`; only
     * full-amount reversal is offered, so a partial request is refused here
     * rather than silently refunding more than asked.
     */
    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        $full = $p->refundableAmount();

        if ($amount !== null && $amount !== $full) {
            return RefundResult::failed($this->key(), 'partial_unsupported', __('billing.payment_error.refund_unsupported'));
        }

        $transId = (string) ($p->authority ?? $p->gateway_ref);

        $response = $this->http()->post(self::BASE.'/verify', [
            'api_key' => (string) $this->config('api_key'),
            'trans_id' => $transId,
            'amount' => $full,
            'currency' => 'IRR',
            'refund_request' => 'yes_money_back',
        ]);

        $code = (int) ($response->json('code') ?? -999);

        return $code === self::CODE_REFUNDED
            ? RefundResult::ok($this->key(), $full, $transId, (array) $response->json())
            : RefundResult::failed($this->key(), (string) $code, null, (array) $response->json());
    }

    private function amountForTransId(string $transId): ?int
    {
        return Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $this->key()->value)
            ->where('authority', $transId)
            ->value('amount');
    }
}
