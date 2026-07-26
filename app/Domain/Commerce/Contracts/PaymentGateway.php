<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\PaymentRequest;
use App\Domain\Commerce\Data\PaymentResult;
use App\Domain\Commerce\Data\PaymentSession;
use App\Domain\Commerce\Data\RefundResult;
use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Models\Payment;

/**
 * Adding a gateway is one class plus one config row (docs/09 §4).
 */
interface PaymentGateway
{
    public function key(): PaymentGatewayKey;

    public function isConfigured(): bool;

    public function createPayment(PaymentRequest $r): PaymentSession;

    /**
     * Ask the gateway's own API whether $reference was really paid.
     *
     * Implementations must never infer success from the callback query string.
     */
    public function verify(string $reference): PaymentResult;

    /**
     * @param  int|null  $amount  Minor units; null refunds the full amount.
     */
    public function refund(Payment $p, ?int $amount = null): RefundResult;

    /**
     * Authenticate an inbound callback/webhook before it is allowed to move a
     * payment forward. $payload is the raw request body.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyCallbackSignature(string $payload, array $headers): bool;
}
