<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\PaymentGatewayKey;

final readonly class RefundResult
{
    /**
     * @param  int  $amount  Refunded amount in minor units.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $successful,
        public PaymentGatewayKey $gateway,
        public int $amount = 0,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public static function ok(PaymentGatewayKey $gateway, int $amount, ?string $reference = null, array $raw = []): self
    {
        return new self(true, $gateway, $amount, $reference, raw: $raw);
    }

    public static function failed(PaymentGatewayKey $gateway, ?string $errorCode = null, ?string $message = null, array $raw = []): self
    {
        return new self(false, $gateway, 0, null, $errorCode, $message, $raw);
    }
}
