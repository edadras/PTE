<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\PaymentGatewayKey;

/**
 * Everything a gateway needs to open a payment session.
 *
 * No card fields by design — the customer always types those on the gateway's
 * own page (docs/09 §7).
 */
final readonly class PaymentRequest
{
    /**
     * @param  int  $amount  Integer minor units of $currency.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $academyId,
        public int $amount,
        public string $currency = 'IRR',
        public string $description = '',
        public ?string $callbackUrl = null,
        public ?PaymentGatewayKey $gateway = null,
        public ?string $payerEmail = null,
        public ?string $payerMobile = null,
        public ?string $payerName = null,
        public ?string $orderId = null,
        public ?string $payableType = null,
        public ?int $payableId = null,
        public array $metadata = [],
    ) {}

    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function withOrderId(string $orderId): self
    {
        return new self(
            $this->academyId, $this->amount, $this->currency, $this->description,
            $this->callbackUrl, $this->gateway, $this->payerEmail, $this->payerMobile,
            $this->payerName, $orderId, $this->payableType, $this->payableId, $this->metadata,
        );
    }
}
