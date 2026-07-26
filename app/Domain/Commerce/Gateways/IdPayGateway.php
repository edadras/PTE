<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Gateways;

use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Data\PaymentResult;
use App\Domain\Commerce\Data\PaymentSession;
use App\Domain\Commerce\Data\RefundResult;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Http\Client\PendingRequest;

/**
 * IDPay v1.1.
 *
 * config('services.idpay'): api_key, sandbox, (optional) callback_url.
 *
 * IDPay posts the result to our callback, but a POST body is trivially forged,
 * so nothing is trusted until /payment/verify answers 100 or 101.
 */
final class IdPayGateway extends AbstractGateway
{
    private const BASE = 'https://api.idpay.ir/v1.1';

    private const STATUS_VERIFIED = 100;

    private const STATUS_ALREADY_VERIFIED = 101;

    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::IdPay;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('api_key'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $orderId = $this->orderId($r);

        $response = $this->headers()->post(self::BASE.'/payment', array_filter([
            'order_id' => $orderId,
            'amount' => $r->amount,
            'name' => $r->payerName,
            'phone' => $r->payerMobile,
            'mail' => $r->payerEmail,
            'desc' => $r->description,
            'callback' => $this->callbackUrl($r),
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $id = (string) ($response->json('id') ?? '');
        $link = (string) ($response->json('link') ?? '');

        if ($id === '' || $link === '') {
            $this->fail('error_code='.json_encode($response->json('error_code') ?? $response->status()));
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $id,
            redirectUrl: $link,
            raw: ['order_id' => $orderId] + (array) $response->json(),
        );
    }

    public function verify(string $reference, ?string $orderId = null): PaymentResult
    {
        $orderId ??= $this->orderIdFor($reference);

        if ($orderId === null) {
            return PaymentResult::failed($this->key(), $reference, 'unknown_reference');
        }

        $response = $this->headers()->post(self::BASE.'/payment/verify', [
            'id' => $reference,
            'order_id' => $orderId,
        ]);

        $status = (int) ($response->json('status') ?? 0);

        if (! in_array($status, [self::STATUS_VERIFIED, self::STATUS_ALREADY_VERIFIED], true)) {
            return PaymentResult::failed($this->key(), $reference, (string) $status, null, (array) $response->json());
        }

        $payment = (array) ($response->json('payment') ?? []);

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: (string) ($response->json('track_id') ?? ''),
            amount: (int) ($payment['amount'] ?? 0),
            currency: 'IRR',
            cardMask: isset($payment['card_no']) ? (string) $payment['card_no'] : null,
            raw: (array) $response->json(),
        );
    }

    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        // IDPay exposes refunds only to contracted merchants; without that the
        // honest answer is "do it in the panel", not a silent success.
        return RefundResult::failed($this->key(), 'unsupported', __('billing.payment_error.refund_unsupported'));
    }

    private function orderIdFor(string $reference): ?string
    {
        $meta = Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $this->key()->value)
            ->where('authority', $reference)
            ->value('meta');

        $decoded = is_string($meta) ? json_decode($meta, true) : $meta;

        return is_array($decoded) && isset($decoded['order_id']) ? (string) $decoded['order_id'] : null;
    }

    private function headers(): PendingRequest
    {
        return $this->http()->withHeaders(array_filter([
            'X-API-KEY' => (string) $this->config('api_key'),
            'X-SANDBOX' => $this->config('sandbox', true) ? '1' : null,
        ]));
    }
}
