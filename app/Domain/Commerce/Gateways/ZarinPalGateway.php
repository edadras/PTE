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
 * ZarinPal REST v4.
 *
 * config('services.zarinpal'): merchant_id, sandbox, (optional) callback_url.
 *
 * Amounts are Rials — the same minor unit we store, so no conversion.
 */
final class ZarinPalGateway extends AbstractGateway
{
    private const LIVE_BASE = 'https://payment.zarinpal.com';

    private const SANDBOX_BASE = 'https://sandbox.zarinpal.com';

    /** 100 = paid, 101 = already verified (a duplicate callback, still valid). */
    private const CODE_OK = 100;

    private const CODE_ALREADY_VERIFIED = 101;

    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::ZarinPal;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('merchant_id'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $response = $this->http()->post($this->base().'/pg/v4/payment/request.json', [
            'merchant_id' => (string) $this->config('merchant_id'),
            'amount' => $r->amount,
            'currency' => 'IRR',
            'description' => $r->description !== '' ? $r->description : __('billing.invoice.line_payment'),
            'callback_url' => $this->callbackUrl($r),
            'metadata' => array_filter([
                'email' => $r->payerEmail,
                'mobile' => $r->payerMobile,
                'order_id' => $this->orderId($r),
            ]),
        ]);

        $data = (array) ($response->json('data') ?? []);
        $authority = (string) ($data['authority'] ?? '');

        if ((int) ($data['code'] ?? 0) !== self::CODE_OK || $authority === '') {
            $this->fail('code='.json_encode($response->json('errors') ?? $response->status()));
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $authority,
            redirectUrl: $this->base().'/pg/StartPay/'.$authority,
            raw: $data,
        );
    }

    /**
     * $reference is the Authority. The amount is re-sent and ZarinPal rejects a
     * mismatch, which is what stops a tampered callback from settling.
     */
    public function verify(string $reference, ?int $amount = null): PaymentResult
    {
        $amount ??= $this->amountForAuthority($reference);

        if ($amount === null) {
            return PaymentResult::failed($this->key(), $reference, 'unknown_authority');
        }

        $response = $this->http()->post($this->base().'/pg/v4/payment/verify.json', [
            'merchant_id' => (string) $this->config('merchant_id'),
            'amount' => $amount,
            'authority' => $reference,
        ]);

        $data = (array) ($response->json('data') ?? []);
        $code = (int) ($data['code'] ?? 0);

        if (! in_array($code, [self::CODE_OK, self::CODE_ALREADY_VERIFIED], true)) {
            return PaymentResult::failed($this->key(), $reference, (string) $code, (string) ($data['message'] ?? ''), $data);
        }

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: (string) ($data['ref_id'] ?? ''),
            amount: $amount,
            currency: 'IRR',
            cardMask: isset($data['card_pan']) ? (string) $data['card_pan'] : null,
            raw: $data,
        );
    }

    /** ZarinPal refunds require the merchant panel / a separate contract. */
    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        return RefundResult::failed($this->key(), 'unsupported', __('billing.payment_error.refund_unsupported'));
    }

    private function amountForAuthority(string $authority): ?int
    {
        return Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $this->key()->value)
            ->where('authority', $authority)
            ->value('amount');
    }

    private function base(): string
    {
        return $this->config('sandbox', true) ? self::SANDBOX_BASE : self::LIVE_BASE;
    }
}
