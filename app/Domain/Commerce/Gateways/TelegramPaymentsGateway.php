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
 * In-bot payments (docs/09 §4).
 *
 * config('services.telegram_payments'): provider_token, bot_token, secret_token.
 * A per-academy bot or provider token may override those through the request
 * metadata, because each academy runs its own bot.
 *
 * Telegram has no "fetch this payment" endpoint: the money is confirmed by the
 * `successful_payment` update. Verification therefore means "did an
 * authenticated webhook deliver a charge id for this invoice payload, and does
 * the amount match what we asked for" — the payload alone proves nothing.
 */
final class TelegramPaymentsGateway extends AbstractGateway
{
    public function key(): PaymentGatewayKey
    {
        return PaymentGatewayKey::Telegram;
    }

    public function isConfigured(): bool
    {
        return filled($this->config('provider_token'));
    }

    public function createPayment(PaymentRequest $r): PaymentSession
    {
        $payload = $this->orderId($r);
        $botToken = (string) ($r->metadata('bot_token') ?? $this->config('bot_token', ''));
        $providerToken = (string) ($r->metadata('provider_token') ?? $this->config('provider_token', ''));

        if ($botToken === '' || $providerToken === '') {
            $this->fail('missing bot_token or provider_token');
        }

        $response = $this->http()->post($this->apiUrl($botToken, 'createInvoiceLink'), [
            'title' => mb_substr($r->description !== '' ? $r->description : __('billing.invoice.line_payment'), 0, 32),
            'description' => mb_substr($r->description !== '' ? $r->description : __('billing.invoice.line_payment'), 0, 255),
            'payload' => $payload,
            'provider_token' => $providerToken,
            'currency' => strtoupper($r->currency),
            'prices' => [[
                'label' => mb_substr($r->description !== '' ? $r->description : __('billing.invoice.line_payment'), 0, 32),
                'amount' => $r->amount,
            ]],
        ]);

        if ($response->json('ok') !== true) {
            $this->fail((string) ($response->json('description') ?? $response->status()));
        }

        return new PaymentSession(
            gateway: $this->key(),
            reference: $payload,
            redirectUrl: (string) $response->json('result'),
            raw: (array) $response->json(),
        );
    }

    /**
     * $reference is the invoice payload we generated. Only a payment whose
     * charge id arrived through an authenticated webhook counts.
     */
    public function verify(string $reference): PaymentResult
    {
        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $this->key()->value)
            ->where('authority', $reference)
            ->first();

        if (! $payment instanceof Payment) {
            return PaymentResult::failed($this->key(), $reference, 'unknown_payload');
        }

        $meta = $payment->meta ?? [];
        $chargeId = (string) ($meta['telegram_payment_charge_id'] ?? '');
        $confirmedAmount = (int) ($meta['confirmed_amount'] ?? 0);

        if ($chargeId === '') {
            return PaymentResult::failed($this->key(), $reference, 'awaiting_successful_payment');
        }

        if ($confirmedAmount !== $payment->amount) {
            return PaymentResult::failed($this->key(), $reference, 'amount_mismatch');
        }

        return PaymentResult::paid(
            gateway: $this->key(),
            reference: $reference,
            gatewayRef: $chargeId,
            amount: $confirmedAmount,
            currency: $payment->currency,
            raw: $meta,
        );
    }

    /**
     * Record what a `successful_payment` update carried, so verify() has
     * something server-side to check against.
     *
     * @param  array<string, mixed>  $successfulPayment  The Telegram SuccessfulPayment object.
     */
    public function captureSuccessfulPayment(array $successfulPayment): PaymentResult
    {
        $payload = (string) ($successfulPayment['invoice_payload'] ?? '');

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->withoutGlobalScope('academy')
            ->where('gateway', $this->key()->value)
            ->where('authority', $payload)
            ->first();

        if (! $payment instanceof Payment) {
            return PaymentResult::failed($this->key(), $payload, 'unknown_payload');
        }

        $payment->forceFill([
            'meta' => array_merge($payment->meta ?? [], [
                'telegram_payment_charge_id' => (string) ($successfulPayment['telegram_payment_charge_id'] ?? ''),
                'provider_payment_charge_id' => (string) ($successfulPayment['provider_payment_charge_id'] ?? ''),
                'confirmed_amount' => (int) ($successfulPayment['total_amount'] ?? 0),
                'confirmed_currency' => (string) ($successfulPayment['currency'] ?? $payment->currency),
            ]),
        ])->save();

        return $this->verify($payload);
    }

    /** Telegram refunds are issued by the payment provider, not the Bot API. */
    public function refund(Payment $p, ?int $amount = null): RefundResult
    {
        return RefundResult::failed($this->key(), 'unsupported', __('billing.payment_error.refund_unsupported'));
    }

    /**
     * Telegram authenticates webhooks with the secret token we registered at
     * setWebhook time — a constant-time compare, not a string equality.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyCallbackSignature(string $payload, array $headers): bool
    {
        $expected = (string) $this->config('secret_token', '');

        if ($expected === '') {
            return false;
        }

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'x-telegram-bot-api-secret-token') {
                $received = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;

                return hash_equals($expected, $received);
            }
        }

        return false;
    }

    private function apiUrl(string $botToken, string $method): string
    {
        return rtrim((string) config('pte.telegram.api_base_url', 'https://api.telegram.org'), '/')
            .'/bot'.$botToken.'/'.$method;
    }
}
