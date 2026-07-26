<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

use App\Domain\Commerce\Enums\PaymentGatewayKey;
use Carbon\CarbonInterface;

/**
 * A started, not-yet-paid payment: where to send the payer and what reference
 * to verify against later.
 */
final readonly class PaymentSession
{
    /**
     * @param  string  $reference  Gateway token we must present at verify time.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentGatewayKey $gateway,
        public string $reference,
        public ?string $redirectUrl = null,
        public ?CarbonInterface $expiresAt = null,
        public array $raw = [],
    ) {}

    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
