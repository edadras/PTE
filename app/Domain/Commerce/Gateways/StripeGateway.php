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
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout, spoken over the REST API directly so the SDK is not a
 * dependency of this domain.
 *
 * config('services.stripe'): key (publishable), secret, webhook_secret.
 *
 * Stripe amounts are already in the smallest currency unit, which is exactly
 * how we store money — no conversion, no floats.
 */
final class StripeGateway extends AbstractGateway
{
    private const BASE = 'https://api.stripe.com/v1';

    /** Reject a webhook whose timestamp is older than this (replay defence). */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::Stripe;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('secret'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $currency = strtolower($r->currency);
        $callback = $this->callbackUrl($r);

        $response = $this->form()->post(self::BASE.'/checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => $callback.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $callback.'?canceled=1',
            'client_reference_id' => $this->orderId($r),
            'customer_email' => $r->payerEmail,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => $r->amount,
            'line_items[0][price_data][product_data][name]' => $r->description !== ''
                ? $r->description
                : __('billing.invoice.line_payment'),
            'metadata[academy_id]' => $r->academyId,
            'metadata[payable_type]' => $r->payableType,
            'metadata[payable_id]' => $r->payableId,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $id = (string) ($response->json('id') ?? '');
        $url = (string) ($response->json('url') ?? '');

        if ($id === '' || $url === '') {
            $this->fail((string) ($response->json('error.message') ?? $response->status()));
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $id,
            redirectUrl: $url,
            raw: (array) $response->json(),
        );
    }

    /**
     * $reference is the Checkout Session id. We re-read it from Stripe rather
     * than believing the browser that came back to success_url.
     */
    public function verify(string $reference): PaymentResult
    {
        $response = $this->form()->get(self::BASE.'/checkout/sessions/'.$reference);

        if ($response->failed()) {
            return PaymentResult::failed($this->key(), $reference, (string) $response->status(), (string) $response->json('error.message'));
        }

        $status = (string) ($response->json('payment_status') ?? '');

        if ($status !== 'paid') {
            return PaymentResult::failed($this->key(), $reference, $status !== '' ? $status : 'unpaid', null, (array) $response->json());
        }

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: (string) ($response->json('payment_intent') ?? $reference),
            amount: (int) ($response->json('amount_total') ?? 0),
            currency: strtoupper((string) ($response->json('currency') ?? 'USD')),
            raw: (array) $response->json(),
        );
    }

    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        $intent = $p->gateway_ref;

        if (blank($intent)) {
            return RefundResult::failed($this->key(), 'missing_payment_intent');
        }

        $response = $this->form()->post(self::BASE.'/refunds', [
            'payment_intent' => $intent,
            'amount' => $amount ?? $p->refundableAmount(),
        ]);

        if ($response->failed() || ($response->json('status') === 'failed')) {
            return RefundResult::failed($this->key(), (string) $response->json('error.code'), (string) $response->json('error.message'), (array) $response->json());
        }

        return RefundResult::ok(
            $this->key(),
            (int) ($response->json('amount') ?? 0),
            (string) ($response->json('id') ?? ''),
            (array) $response->json(),
        );
    }

    /**
     * Stripe signs every webhook. Without this check anyone who learns the URL
     * can mark invoices paid, so an unsigned body is simply not a webhook.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyCallbackSignature(string $payload, array $headers): bool
    {
        $secret = (string) $this->config('webhook_secret', '');
        $header = $this->header($headers, 'stripe-signature');

        if ($secret === '' || $header === null) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, string>  $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }

    private function form(): PendingRequest
    {
        return Http::asForm()
            ->acceptJson()
            ->withToken((string) $this->config('secret'))
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->retry(2, 200, throw: false);
    }
}
