<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\PaymentGatewayKey;
use App\Domain\Commerce\Enums\PaymentStatus;

/**
 * Outcome of a *server-side* verification. A redirect landing on our callback
 * URL is never enough on its own (docs/09 §7).
 */
final readonly class PaymentResult
{
    /**
     * @param  int|null  $amount  Amount confirmed by the gateway, in minor units.
     * @param  string|null  $cardMask  Masked PAN as returned by the gateway; the
     *                                 full number is never requested or stored.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $successful,
        public PaymentGatewayKey $gateway,
        public string $reference,
        public PaymentStatus $status = PaymentStatus::Pending,
        public ?string $gatewayRef = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $cardMask = null,
        public ?string $errorCode = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public static function failed(
        PaymentGatewayKey $gateway,
        string $reference,
        ?string $errorCode = null,
        ?string $message = null,
        array $raw = [],
    ): self {
        return new self(
            successful: false,
            gateway: $gateway,
            reference: $reference,
            status: PaymentStatus::Failed,
            errorCode: $errorCode,
            message: $message,
            raw: $raw,
        );
    }

    public static function paid(
        PaymentGatewayKey $gateway,
        string $reference,
        string $gatewayRef,
        int $amount,
        string $currency = 'IRR',
        ?string $cardMask = null,
        array $raw = [],
    ): self {
        return new self(
            successful: true,
            gateway: $gateway,
            reference: $reference,
            status: PaymentStatus::Paid,
            gatewayRef: $gatewayRef,
            amount: $amount,
            currency: $currency,
            cardMask: $cardMask,
            raw: $raw,
        );
    }
}
