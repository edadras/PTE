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
 * Zibal.
 *
 * config('services.zibal'): merchant, (optional) callback_url.
 */
final class ZibalGateway extends AbstractGateway
{
    private const BASE = 'https://gateway.zibal.ir';

    private const RESULT_OK = 100;

    /** Already verified — a repeated callback, not a failure. */
    private const RESULT_ALREADY_VERIFIED = 201;

    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::Zibal;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('merchant'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $response = $this->http()->post(self::BASE.'/v1/request', array_filter([
            'merchant' => (string) $this->config('merchant'),
            'amount' => $r->amount,
            'callbackUrl' => $this->callbackUrl($r),
            'description' => $r->description,
            'orderId' => $this->orderId($r),
            'mobile' => $r->payerMobile,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $result = (int) ($response->json('result') ?? 0);
        $trackId = (string) ($response->json('trackId') ?? '');

        if ($result !== self::RESULT_OK || $trackId === '') {
            $this->fail('result='.$result.' message='.(string) $response->json('message'));
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $trackId,
            redirectUrl: self::BASE.'/start/'.$trackId,
            raw: (array) $response->json(),
        );
    }

    public function verify(string $reference): PaymentResult
    {
        $response = $this->http()->post(self::BASE.'/v1/verify', [
            'merchant' => (string) $this->config('merchant'),
            'trackId' => (int) $reference,
        ]);

        $result = (int) ($response->json('result') ?? 0);

        if (! in_array($result, [self::RESULT_OK, self::RESULT_ALREADY_VERIFIED], true)) {
            return PaymentResult::failed($this->key(), $reference, (string) $result, (string) $response->json('message'), (array) $response->json());
        }

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: (string) ($response->json('refNumber') ?? $reference),
            amount: (int) ($response->json('amount') ?? 0),
            currency: 'IRR',
            cardMask: ($pan = $response->json('cardNumber')) !== null ? (string) $pan : null,
            raw: (array) $response->json(),
        );
    }

    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        $response = $this->http()->post(self::BASE.'/v1/refund', [
            'merchant' => (string) $this->config('merchant'),
            'trackId' => (int) ($p->authority ?? $p->gateway_ref),
            'amount' => $amount ?? $p->refundableAmount(),
        ]);

        $result = (int) ($response->json('result') ?? 0);

        return $result === self::RESULT_OK
            ? RefundResult::ok($this->key(), $amount ?? $p->refundableAmount(), $p->gateway_ref, (array) $response->json())
            : RefundResult::failed($this->key(), (string) $result, (string) $response->json('message'), (array) $response->json());
    }
}
